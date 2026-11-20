<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\VectorAi\Auth\AiAccessInterface;
use Kinetis\VectorAi\Feature;

/** A provider configured, and an access policy that lets nobody use AI: 403, and the flag is off. */
final class FiltersRefusedTest extends AiTestCase
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
        $app->instance(AiAccessInterface::class, new RefusingAiAccess());
    }

    public function testAPolicyThatRefusesIsForbidden(): void
    {
        $this->client->post('/vector/ai/filters/orders', ['text' => 'paid orders'], $this->aiHeaders())->assertStatus(403);
        self::assertFalse($this->client->get('/vector/schema', headers: $this->aiHeaders())->assertOk()->json()['capabilities']['ai']['filters'] ?? null);
    }
}

/** Lets nobody use AI, and anyone configure it. */
final readonly class RefusingAiAccess implements AiAccessInterface
{
    #[\Override]
    public function canUse(CurrentUserInterface $user, Feature $feature): bool
    {
        return false;
    }

    #[\Override]
    public function canConfigure(CurrentUserInterface $user): bool
    {
        return true;
    }
}
