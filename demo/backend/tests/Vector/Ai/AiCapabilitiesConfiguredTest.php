<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Shell\AiShellContributor;

/**
 * With a provider configured, a model resolvable and the switches on, the
 * features this release ships are offered — plain-language filters —
 * and the others are not; `excluded` names the resources kept from the
 * model (the demo's profiles opt out). Computed per user, so it follows
 * the switch and the budget too.
 */
final class AiCapabilitiesConfiguredTest extends AiTestCase
{
    #[\Override]
    protected function aiConfig(): array
    {
        return ['VECTOR_AI_PROVIDERS' => 'test-fake', 'VECTOR_AI_TEST_FAKE_TYPE' => 'fake'];
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

    public function testFiltersAreOfferedAndTheOptedOutResourceIsExcluded(): void
    {
        self::assertSame([Feature::Filters], AiShellContributor::shipped());

        $response = $this->client->get('/vector/schema', headers: $this->headers());
        $ai = $response->assertOk()->json()['capabilities']['ai'] ?? null;

        self::assertSame(['filters' => true, 'assistant' => false, 'assistantWrites' => false, 'writing' => false, 'excluded' => ['profiles' => true]], $ai);
    }

    public function testTheSwitchAndTheBudgetTurnFiltersOff(): void
    {
        $this->client->request('PUT', '/vector/settings/ai', body: ['filtersEnabled' => false], headers: $this->adminHeaders())->assertOk();
        $off = $this->client->get('/vector/schema', headers: $this->headers())->assertOk();
        self::assertFalse($off->json()['capabilities']['ai']['filters'] ?? null);
        self::assertStringContainsString('"excluded":{}', $off->body(), 'nothing is excluded while no feature sends to a model');

        $this->client->request('PUT', '/vector/settings/ai', body: ['filtersEnabled' => true, 'dailyTokenBudgetPerUser' => 100], headers: $this->adminHeaders())->assertOk();
        $this->vectorQuery()->table('vector_ai_usage')->insert([
            'actor_id' => self::ACTOR_PREFIX . '01', 'impersonator_id' => null, 'feature' => 'filters', 'provider' => 'test-fake', 'model' => 'fake',
            'input_tokens' => 60, 'output_tokens' => 40, 'duration_ms' => 1, 'tool_calls' => 0, 'outcome' => 'answered',
            'created_at' => gmdate('Y-m-d H:i:s.000000'),
        ]);

        self::assertFalse($this->client->get('/vector/schema', headers: $this->headers())->assertOk()->json()['capabilities']['ai']['filters'] ?? null);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->aiHeaders(1);
    }
}
