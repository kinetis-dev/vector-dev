<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

/**
 * Who may ask, and when: an unknown resource is 404; the switch off, or a
 * budget of 0, is 403; today's budget spent is 429 with Retry-After until
 * midnight UTC. Nothing is sent to the model in any of those cases.
 */
final class FiltersAccessTest extends FiltersTestCase
{
    public function testAnUnknownResourceIsNotFound(): void
    {
        $this->ask('nothing-here', 'anything')->assertStatus(404);
        self::assertSame([], $this->calls());
    }

    public function testTheFeatureSwitchedOffIsForbidden(): void
    {
        $this->settings(['filtersEnabled' => false]);
        $this->ask('orders', 'paid orders')->assertStatus(403)->assertJsonPath('error', 'Plain-language filters are not available to this account.');

        $this->settings(['dailyTokenBudgetPerUser' => 0]);
        $this->ask('orders', 'paid orders')->assertStatus(403);

        self::assertSame([], $this->calls());
    }

    public function testASpentBudgetIsTooManyRequestsUntilMidnightUtc(): void
    {
        $this->settings(['dailyTokenBudgetPerUser' => 1000]);
        $this->vectorQuery()->table('vector_ai_usage')->insert([
            'actor_id' => self::ACTOR_PREFIX . '02', 'impersonator_id' => null, 'feature' => 'filters', 'provider' => 'test-fake', 'model' => 'fake',
            'input_tokens' => 900, 'output_tokens' => 100, 'duration_ms' => 1, 'tool_calls' => 0, 'outcome' => 'answered',
            'created_at' => gmdate('Y-m-d H:i:s.000000'),
        ]);
        $this->script(['paid orders' => self::answer([['field' => 'status', 'op' => 'eq', 'value' => 'paid']])]);

        $before = time();
        $spent = $this->ask('orders', 'paid orders', actor: 2)->assertStatus(429);
        $retry = (int) $spent->getHeaderLine('Retry-After');
        $midnight = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC')))->getTimestamp();

        self::assertGreaterThanOrEqual($midnight - time() - 1, $retry);
        self::assertLessThanOrEqual($midnight - $before + 1, $retry);
        self::assertStringContainsString('budget', (string) $spent->json()['error']);
        self::assertSame([], $this->calls());

        // another person's budget is their own
        $this->ask('orders', 'paid orders', actor: 4)->assertOk();
    }

    public function testTheTextIsRequiredAndBounded(): void
    {
        $this->client->post('/vector/ai/filters/orders', ['text' => '  '], $this->aiHeaders())->assertValidationError('text');
        $this->ask('orders', str_repeat('a', 501))->assertValidationError('text');
        self::assertSame([], $this->calls());
    }
}
