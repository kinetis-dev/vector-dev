<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\Neuron\NeuronHttpClient;
use Kinetis\RevoltHttpClient\Http;
use Kinetis\VectorAi\AiTransports;
use Kinetis\VectorAi\Config\AiConfig;
use Kinetis\VectorAi\Config\ProviderType;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Providers\ProviderResolver;
use Kinetis\VectorAi\Secrets\CredentialStore;
use Kinetis\VectorAi\Settings\AiSettingsReader;
use Kinetis\VectorAi\Testing\ScriptedReplies;
use NeuronAI\Chat\Messages\UserMessage;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A feature's deadline narrows VECTOR_AI_TIMEOUT and never lengthens it:
 * a request's own timeout replaces the transport's bound, so with
 * VECTOR_AI_TIMEOUT shorter than the filters' 30 seconds, the wire sees
 * VECTOR_AI_TIMEOUT.
 */
final class ProviderDeadlineTest extends AiTestCase
{
    #[\Override]
    protected function aiConfig(): array
    {
        return [
            'VECTOR_AI_TIMEOUT' => '10',
            'VECTOR_AI_PROVIDERS' => 'test-openai',
            'VECTOR_AI_TEST_OPENAI_TYPE' => 'openai',
            'VECTOR_AI_TEST_OPENAI_KEY' => 'sk-env',
            'VECTOR_AI_TEST_OPENAI_MODELS' => 'gpt-small',
        ];
    }

    public function testAShorterVectorAiTimeoutBoundsAFilterCall(): void
    {
        $config = $this->app->get(AiConfig::class);
        self::assertInstanceOf(AiConfig::class, $config);
        self::assertSame(10.0, $config->timeout);
        self::assertSame(30.0, Feature::Filters->timeout());

        $sent = [];
        $wire = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = $options;

            return new MockResponse((string) json_encode([
                'id' => 'chatcmpl-1',
                'object' => 'chat.completion',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']]);
        });
        $transport = new Http($wire)->withTimeout($config->timeout);
        $transports = new AiTransports([(string) ProviderType::OpenAi->defaultBaseUrl() => new NeuronHttpClient($transport, (string) ProviderType::OpenAi->defaultBaseUrl())]);

        $credentials = $this->app->get(CredentialStore::class);
        $settings = $this->app->get(AiSettingsReader::class);
        $replies = $this->app->get(ScriptedReplies::class);
        self::assertInstanceOf(CredentialStore::class, $credentials);
        self::assertInstanceOf(AiSettingsReader::class, $settings);
        self::assertInstanceOf(ScriptedReplies::class, $replies);
        $user = $this->createStub(CurrentUserInterface::class);
        $user->method('id')->willReturn('test-1');

        $resolved = new ProviderResolver($config, $transports, $credentials, $settings, $replies)->for(Feature::Filters, $user);
        $resolved->provider->chat(new UserMessage('Hello'));

        self::assertCount(1, $sent);
        $bound = $sent[0]['max_duration'] ?? null;
        self::assertIsFloat($bound);
        self::assertGreaterThan(0.0, $bound);
        self::assertLessThanOrEqual(10.0, $bound, 'the filter deadline replaced VECTOR_AI_TIMEOUT instead of staying within it');
    }
}
