<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\Neuron\Bedrock\BedrockProvider;
use Kinetis\VectorAi\Config\AiConfig;
use Kinetis\VectorAi\Failure\AiFailure;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Providers\ProviderResolver;
use Kinetis\VectorAi\Secrets\CredentialStore;
use Kinetis\VectorAi\Testing\ScriptedProvider;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;

/**
 * ProviderResolver: the provider and model the settings name (or the
 * only provider, its first model), the key from the environment else the
 * sealed one, and a new Neuron provider for every call.
 */
final class ProviderResolverTest extends AiTestCase
{
    #[\Override]
    protected function aiConfig(): array
    {
        return [
            'VECTOR_AI_KEY' => self::KEY,
            'VECTOR_AI_PROVIDERS' => 'test-openai,test-claude,test-aws,test-fake',
            'VECTOR_AI_TEST_OPENAI_TYPE' => 'openai',
            'VECTOR_AI_TEST_OPENAI_MODELS' => 'gpt-small',
            'VECTOR_AI_TEST_CLAUDE_TYPE' => 'anthropic',
            'VECTOR_AI_TEST_CLAUDE_KEY' => 'sk-ant-env',
            'VECTOR_AI_TEST_CLAUDE_MODELS' => 'claude-x',
            'VECTOR_AI_TEST_AWS_TYPE' => 'bedrock',
            'VECTOR_AI_TEST_AWS_REGION' => 'eu-central-1',
            'VECTOR_AI_TEST_AWS_MODELS' => 'anthropic.claude-v1',
            'VECTOR_AI_TEST_FAKE_TYPE' => 'fake',
        ];
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'ai')->delete();
        parent::tearDown();
    }

    public function testWithSeveralProvidersAndNoneChosenItIsUnconfigured(): void
    {
        $this->expectException(AiFailure::class);
        $this->expectExceptionMessage('No AI provider is chosen for filters.');

        $this->resolver()->for(Feature::Filters, $this->user());
    }

    public function testTheChosenProviderIsBuiltNewOnEveryCall(): void
    {
        $this->choose(['writingProvider' => 'test-claude', 'assistantProvider' => 'test-aws', 'filtersProvider' => 'test-fake']);
        $resolver = $this->resolver();

        $first = $resolver->for(Feature::Writing, $this->user());
        $second = $resolver->for(Feature::Writing, $this->user());
        self::assertInstanceOf(Anthropic::class, $first->provider);
        self::assertNotSame($first->provider, $second->provider, 'a provider holds a prompt, tools and stream state: one per call');
        self::assertSame('test-claude', $first->name);
        self::assertSame('claude-x', $first->model, 'the first allowed model when none is chosen');
        self::assertSame(2048, $first->maxTokens);

        self::assertInstanceOf(BedrockProvider::class, $resolver->for(Feature::AssistantWrites, $this->user())->provider, 'writes share the assistant\'s provider');

        $fake = $resolver->for(Feature::Filters, $this->user());
        self::assertInstanceOf(ScriptedProvider::class, $fake->provider);
        self::assertSame('fake', $fake->model);
        $answer = $fake->provider->chat(new UserMessage('Hello'));
        self::assertSame(ScriptedProvider::UNSCRIPTED, $answer->message()->getContent());
        self::assertInstanceOf(ScriptedProvider::class, $fake->provider);
        self::assertCount(1, $fake->provider->getRecorded(), 'it records what it was sent');
    }

    public function testAKeyIsTheEnvironmentsElseTheSealedOneElseUnconfigured(): void
    {
        $this->choose(['filtersProvider' => 'test-openai']);

        try {
            $this->resolver()->for(Feature::Filters, $this->user());
            self::fail('a provider without a key resolved');
        } catch (AiFailure $e) {
            self::assertStringContainsString('"test-openai" has no API key', $e->getMessage());
            self::assertSame(503, $e->httpStatus());
        }

        $store = $this->app->get(CredentialStore::class);
        $config = $this->app->get(AiConfig::class);
        self::assertInstanceOf(CredentialStore::class, $store);
        self::assertInstanceOf(AiConfig::class, $config);
        $store->put($config->providers['test-openai'], 'sk-sealed', '1');

        $resolved = $this->resolver()->for(Feature::Filters, $this->user());
        self::assertInstanceOf(OpenAI::class, $resolved->provider);
        self::assertSame('gpt-small', $resolved->model);
    }

    public function testAModelOffTheListIsUnconfigured(): void
    {
        // saved before the operator narrowed the allow-list: the settings endpoint refuses it now
        $this->client->request('PUT', '/vector/settings/ai', body: ['writingProvider' => 'test-claude', 'writingModel' => 'claude-other'], headers: $this->adminHeaders())->assertValidationError('writingModel');
        $this->choose(['writingProvider' => 'test-claude']);
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'ai')->where('key', '=', 'writingModel')->update(['value' => json_encode('claude-other')]);

        $this->expectException(AiFailure::class);
        $this->expectExceptionMessage('is not one test-claude allows');
        $this->resolver()->for(Feature::Writing, $this->user());
    }

    /** @param array<string, string> $values */
    private function choose(array $values): void
    {
        $this->client->request('PUT', '/vector/settings/ai', body: $values, headers: $this->adminHeaders())->assertOk();
    }

    private function resolver(): ProviderResolver
    {
        // a request's own: the settings are read once per resolver
        $resolver = $this->app->get(ProviderResolver::class);
        self::assertInstanceOf(ProviderResolver::class, $resolver);

        return $resolver;
    }

    private function user(): CurrentUserInterface
    {
        $user = $this->createStub(CurrentUserInterface::class);
        $user->method('id')->willReturn('test-1');

        return $user;
    }
}
