<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\VectorAi\Auth\AiAccessInterface;
use Kinetis\VectorAi\Feature;

/**
 * capabilities.ai offers a feature exactly when its endpoint would serve
 * it (AiAvailability): proposing changes is the assistant's, so it is not
 * offered to someone the access policy refuses the assistant, even with
 * its own switch on and Feature::AssistantWrites allowed — the approve
 * endpoint would refuse them.
 */
final class AiCapabilitiesAccessTest extends AiTestCase
{
    #[\Override]
    protected function aiConfig(): array
    {
        return ['VECTOR_AI_PROVIDERS' => 'test-fake', 'VECTOR_AI_TEST_FAKE_TYPE' => 'fake'];
    }

    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(AiAccessInterface::class, new NoAssistantAccess());
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

    public function testWritesAreNotOfferedToSomeoneTheAssistantIsNot(): void
    {
        $this->client->request('PUT', '/vector/settings/ai', body: ['assistantWrites' => true], headers: $this->adminHeaders())->assertOk();
        $ai = $this->client->get('/vector/schema', headers: $this->aiHeaders(1))->assertOk()->json()['capabilities']['ai'] ?? null;

        self::assertIsArray($ai);
        self::assertTrue($ai['filters']);
        self::assertFalse($ai['assistant']);
        self::assertFalse($ai['assistantWrites']);
    }
}

/** Lets anyone use every feature but the assistant, and configure. */
final readonly class NoAssistantAccess implements AiAccessInterface
{
    #[\Override]
    public function canUse(CurrentUserInterface $user, Feature $feature): bool
    {
        return $feature !== Feature::Assistant;
    }

    #[\Override]
    public function canConfigure(CurrentUserInterface $user): bool
    {
        return true;
    }
}
