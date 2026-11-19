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
 * make Neuron block the worker on curl), and parallel tool calls, which
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
     * @throws AiFailure (unavailable) when no provider, model or key is configured for $feature
     */
    public function for(Feature $feature, CurrentUserInterface $user): ResolvedProvider
    {
        $provider = $this->providerFor($feature);
        $model = $this->modelFor($feature, $provider);
        $key = $provider->key ?? ($provider->type->takesKey() ? $this->credentials->key($provider) : null);

        if ($key === null && $provider->type->needsKey()) {
            throw AiFailure::unconfigured("The AI provider \"{$provider->name}\" has no API key: set it in the environment, or store one in the AI settings.");
        }

        return new ResolvedProvider($this->build($provider, $model, (string) $key), $provider->name, $model, $this->config->maxOutputTokens);
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

    private function build(ProviderConfig $provider, string $model, #[\SensitiveParameter] string $key): \NeuronAI\Providers\AIProviderInterface
    {
        $maxTokens = $this->config->maxOutputTokens;

        return match ($provider->type) {
            ProviderType::OpenAi => $provider->baseUrl === ProviderType::OpenAi->defaultBaseUrl()
                ? new OpenAI($key, $model, ['max_completion_tokens' => $maxTokens], false, $this->transports->http($provider))
                : new OpenAILike((string) $provider->baseUrl, $key, $model, ['max_tokens' => $maxTokens], false, $this->transports->http($provider)),
            ProviderType::OpenAiLike => new OpenAILike((string) $provider->baseUrl, $key, $model, ['max_tokens' => $maxTokens], false, $this->transports->http($provider)),
            ProviderType::Anthropic => new Anthropic($key, $model, '2023-06-01', $maxTokens, [], $this->transports->http($provider)),
            ProviderType::Ollama => new Ollama((string) $provider->baseUrl, $model, ['options' => ['num_predict' => $maxTokens]], $this->transports->http($provider)),
            ProviderType::Bedrock => new BedrockProvider($this->transports->bedrock($provider), $model, ['maxTokens' => $maxTokens], $this->config->maxResponseBytes),
            ProviderType::Fake => new ScriptedProvider($this->replies),
        };
    }
}
