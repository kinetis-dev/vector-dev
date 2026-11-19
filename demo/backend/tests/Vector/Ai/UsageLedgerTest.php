<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Usage\UsageLedger;

/** The usage ledger: what a call recorded, and what the daily budget leaves today. */
final class UsageLedgerTest extends AiTestCase
{
    public function testRemainingSubtractsTodaysTokensOfThatActorOnly(): void
    {
        $ledger = $this->app->get(UsageLedger::class);
        self::assertInstanceOf(UsageLedger::class, $ledger);

        $ledger->record('test-a', null, Feature::Writing, 'test-fake', 'fake', 300, 200, 15, 0, 'ok');
        $ledger->record('test-a', 'test-admin', Feature::Assistant, 'test-fake', 'fake', 1000, 500, 40, 2, 'ok');
        $ledger->record('test-b', null, Feature::Filters, 'test-fake', 'fake', 9999, 1, 5, 0, 'ok');
        // yesterday's tokens do not count against today
        $this->vectorQuery()->table('vector_ai_usage')->insert(['actor_id' => 'test-a', 'impersonator_id' => null, 'feature' => 'writing', 'provider' => 'test-fake', 'model' => 'fake', 'input_tokens' => 50000, 'output_tokens' => 0, 'duration_ms' => 1, 'tool_calls' => 0, 'outcome' => 'ok', 'created_at' => (new \DateTimeImmutable('yesterday', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')]);

        self::assertSame(2000, $ledger->spentToday('test-a'));
        self::assertSame(8000, $ledger->remaining('test-a', 10000));
        self::assertSame(0, $ledger->remaining('test-b', 10000), 'never below zero');
        self::assertSame(0, $ledger->remaining('test-a', 0), 'a budget of 0 is no AI');

        $row = $this->vectorQuery()->table('vector_ai_usage')->where('actor_id', '=', 'test-a')->where('feature', '=', 'assistant')->first();
        self::assertIsArray($row);
        self::assertSame('test-admin', $row['impersonator_id']);
        self::assertSame(2, (int) $row['tool_calls']);
    }
}
