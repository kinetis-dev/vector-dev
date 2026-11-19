<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

/**
 * With a provider configured, a model resolvable and the switches on, a
 * feature is still off until a release ships it: increment 0 ships none.
 * Computed per user, so it follows the budget too.
 */
final class AiCapabilitiesConfiguredTest extends AiTestCase
{
    #[\Override]
    protected function aiConfig(): array
    {
        return ['VECTOR_AI_PROVIDERS' => 'test-fake', 'VECTOR_AI_TEST_FAKE_TYPE' => 'fake'];
    }

    public function testNoFeatureIsOfferedBeforeOneShips(): void
    {
        $ai = $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->json()['capabilities']['ai'] ?? null;

        self::assertSame(['filters' => false, 'assistant' => false, 'assistantWrites' => false, 'writing' => false, 'excluded' => []], $ai);
        self::assertSame([], \Kinetis\VectorAi\Shell\AiShellContributor::shipped());
    }
}
