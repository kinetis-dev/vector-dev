<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Providers;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\Neuron\Bedrock\BedrockProvider;
use Kinetis\VectorAi\AiTransports;
use Kinetis\VectorAi\Config\AiConfig;
use Kinetis\VectorAi\Config\ProviderConfig;
use Kinetis\VectorAi\Config\ProviderType;
use Kinetis\VectorAi\Failure\AiFailure;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Secrets\CredentialStore;
use Kinetis\VectorAi\Settings\AiSettingsReader;
use Kinetis\VectorAi\Testing\ScriptedProvider;
use Kinetis\VectorAi\Testing\ScriptedReplies;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAILike;

/**
 * Which provider and model a feature calls, built fresh for that call.
 *
 * The provider is the one the `ai` settings name for the feature, or the
 * only configured one; the model is the one they name, or the provider's
 * first allowed one. The key is the environment's
 * (VECTOR_AI_<NAME>_KEY), else the one an administrator sealed, else the
 * provider is unconfigured. A Neuron provider holds a system prompt,
 * tools and stream state, so a new one is built on every call, always
 * over the app-scoped transport (never an empty HTTP client, which would
 * make Neuron block the worker on curl) and under the feature's own
 * deadline when it has a shorter one (Feature::timeout(); a Bedrock
 * call keeps its signing client's) — VECTOR_AI_TIMEOUT otherwise — and parallel tool calls, which
 * fork the worker, are never turned on. Built per request.
 */
final readonly class ProviderResolver
{
    public function __construct(
        private AiConfig $config,
        private AiTransports $transports,
        private CredentialStore $credentials,
        private AiSettingsReader $settings,
        private ScriptedReplies $replies,
    ) {}

    /**
     * $shouldStop, when given, is asked before every event a streamed
     * answer reads, and ends the answer with what it has when it is true:
     * a browser that left (StoppableHttpClient). A Bedrock call cannot
     * take it, and the `fake` provider reads nothing.
     *
     * @param (\Closure(): bool)|null $shouldStop
     * @throws AiFailure (unavailable) when no provider, model or key is configured for $feature
     */
    public function for(Feature $feature, CurrentUserInterface $user, ?\Closure $shouldStop = null): ResolvedProvider
    {
        $provider = $this->providerFor($feature);
        $model = $this->modelFor($feature, $provider);
        $key = $provider->key ?? ($provider->type->takesKey() ? $this->credentials->key($provider) : null);

        if ($key === null && $provider->type->needsKey()) {
            throw AiFailure::unconfigured("The AI provider \"{$provider->name}\" has no API key: set it in the environment, or store one in the AI settings.");
        }

        return new ResolvedProvider($this->build($provider, $model, (string) $key, $feature->timeout(), $shouldStop), $provider->name, $model, $this->config->maxOutputTokens);
    }

    /**
     * Whether for() would find a provider, a model and a key for
     * $feature, from the configuration, the settings and the stored key's
     * own record alone: no provider is built and no key decrypted, so
     * GET /vector/schema can ask it of every feature on every request. A
     * stored key altered in the database passes here and fails for().
     */
    public function resolvable(Feature $feature): bool
    {
        try {
            $provider = $this->providerFor($feature);
            $this->modelFor($feature, $provider);
        } catch (AiFailure) {
            return false;
        }

        return !$provider->type->needsKey()
            || $provider->key !== null
            || ($provider->type->takesKey() && $this->credentials->stored($provider));
    }

    private function providerFor(Feature $feature): ProviderConfig
    {
        $name = $this->settings->string($feature->providerSetting());

        if ($name === '') {
            return $this->config->soleProvider() ?? throw AiFailure::unconfigured($this->config->providers === []
                ? 'No AI provider is configured.'
                : "No AI provider is chosen for {$feature->value}.");
        }

        return $this->config->provider($name) ?? throw AiFailure::unconfigured("The AI provider \"{$name}\" the settings name is not configured.");
    }

    private function modelFor(Feature $feature, ProviderConfig $provider): string
    {
        $model = $this->settings->string($feature->modelSetting());

        if ($model === '') {
            return $provider->models[0] ?? ($provider->type === ProviderType::Fake ? 'fake' : throw AiFailure::unconfigured("No model is chosen for {$feature->value}."));
        }

        if (!$provider->allows($model)) {
            throw AiFailure::unconfigured("The model \"{$model}\" is not one {$provider->name} allows.");
        }

        return $model;
    }

    /** @param (\Closure(): bool)|null $shouldStop */
    private function build(ProviderConfig $provider, string $model, #[\SensitiveParameter] string $key, ?float $deadline, ?\Closure $shouldStop): \NeuronAI\Providers\AIProviderInterface
    {
        $maxTokens = $this->config->maxOutputTokens;
        // one deadline per call, bounding the send, the first byte and the last,
        // a stream's included: a request's own timeout replaces the transport's
        // bound rather than narrowing it, so it is the shorter of the two
        $seconds = min($deadline ?? $this->config->timeout, $this->config->timeout);
        $http = function () use ($provider, $seconds, $shouldStop): \NeuronAI\HttpClient\HttpClientInterface {
            $client = new DeadlineHttpClient($this->transports->http($provider), $seconds);

            return $shouldStop === null ? $client : new StoppableHttpClient($client, $shouldStop);
        };

        return match ($provider->type) {
            ProviderType::OpenAi => $provider->baseUrl === ProviderType::OpenAi->defaultBaseUrl()
                ? new OpenAI($key, $model, ['max_completion_tokens' => $maxTokens], false, $http())
                : new OpenAILike((string) $provider->baseUrl, $key, $model, ['max_tokens' => $maxTokens], false, $http()),
            ProviderType::OpenAiLike => new OpenAILike((string) $provider->baseUrl, $key, $model, ['max_tokens' => $maxTokens], false, $http()),
            ProviderType::Anthropic => new Anthropic($key, $model, '2023-06-01', $maxTokens, [], $http()),
            ProviderType::Ollama => new Ollama((string) $provider->baseUrl, $model, ['options' => ['num_predict' => $maxTokens]], $http()),
            ProviderType::Bedrock => new BedrockProvider($this->transports->bedrock($provider), $model, ['maxTokens' => $maxTokens], $this->config->maxResponseBytes),
            ProviderType::Fake => new ScriptedProvider($this->replies),
        };
    }
}
