<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\VectorAi\AiTransports;
use Kinetis\VectorAi\Config\AiConfig;
use Kinetis\VectorAi\Secrets\CredentialStore;

/**
 * capabilities.ai decides whether a provider, a model and a key are there
 * from the configuration alone: a provider that needs a key is
 * offered with one stored under VECTOR_AI_KEY as it is now, and not
 * without one — as before — but no provider is built to tell, so a
 * transport the schema never uses cannot turn it off.
 */
final class AiCapabilitiesKeysTest extends AiTestCase
{
    #[\Override]
    protected function aiConfig(): array
    {
        return [
            'VECTOR_AI_KEY' => self::KEY,
            'VECTOR_AI_PROVIDERS' => 'test-openai',
            'VECTOR_AI_TEST_OPENAI_TYPE' => 'openai',
            'VECTOR_AI_TEST_OPENAI_MODELS' => 'gpt-small',
        ];
    }

    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        // no transport for any provider: building one would throw
        $app->instance(AiTransports::class, new AiTransports());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'ai')->delete();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'ai')->delete();
        parent::tearDown();
    }

    public function testAKeyStoredUnderTheCurrentKeyOffersTheFeaturesWithoutBuildingAProvider(): void
    {
        self::assertSame([false, false], [$this->ai()['filters'], $this->ai()['writing']], 'no key: unconfigured');

        $this->storeKey();
        self::assertSame([true, true], [$this->ai()['filters'], $this->ai()['writing']]);

        // sealed under a VECTOR_AI_KEY that has since changed: it would not open
        $this->vectorQuery()->table('vector_ai_credentials')->where('provider', '=', 'test-openai')->update(['key_id' => 'deadbeef']);
        self::assertSame([false, false], [$this->ai()['filters'], $this->ai()['writing']]);
    }

    /** @return array<string, mixed> */
    private function ai(): array
    {
        $ai = $this->client->get('/vector/schema', headers: $this->aiHeaders(1))->assertOk()->json()['capabilities']['ai'] ?? null;
        self::assertIsArray($ai);

        return $ai;
    }

    private function storeKey(): void
    {
        $store = $this->app->get(CredentialStore::class);
        $config = $this->app->get(AiConfig::class);
        self::assertInstanceOf(CredentialStore::class, $store);
        self::assertInstanceOf(AiConfig::class, $config);
        $store->put($config->providers['test-openai'], 'sk-sealed', '1');
    }
}
