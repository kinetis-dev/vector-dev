<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\Queues\Fixtures\SendInvoiceJob;

/** The dashboard's endpoints, as the demo's admin calls them. */
final class QueueDashboardTest extends QueuesTestCase
{
    public function testTheOverviewListsEveryConfiguredQueueWithSizeClearabilityAndFailures(): void
    {
        $this->queue->push(new SendInvoiceJob(1, 'x'), queue: 'qa-mail');
        $this->queue->push(new SendInvoiceJob(2, 'x'), queue: 'qa-mail');
        $this->recordFailure('qa-mail', 1_800_000_000);
        $this->recordFailure('qa-reports', 1_800_000_001);
        $this->recordFailure('qa-reports', 1_800_000_002);
        $this->recordFailure('qa-elsewhere', 1_800_000_003);

        $this->client->get('/vector/queues', headers: $this->adminHeaders())
            ->assertOk()
            ->assertJson(['data' => [
                ['name' => 'qa-mail', 'size' => 2, 'clearable' => true, 'failed' => 1],
                ['name' => 'qa-reports', 'size' => 0, 'clearable' => true, 'failed' => 2],
                ['name' => 'qa-idle', 'size' => 0, 'clearable' => true, 'failed' => 0],
            ]]);
    }

    public function testTheFailedListPagesNewestFirstThroughAnOpaqueCursor(): void
    {
        $ids = [];

        foreach ([10, 20, 20, 30, 40] as $offset) {
            $ids[] = ['id' => $this->recordFailure('qa-mail', 1_800_000_000 + $offset), 'at' => $offset];
        }

        // newest first; two records failed in the same second order by id, descending
        usort($ids, static fn (array $a, array $b): int => [$b['at'], $b['id']] <=> [$a['at'], $a['id']]);
        $expected = array_column($ids, 'id');

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $query = ['queue' => 'qa-mail', 'limit' => '2'] + ($cursor === null ? [] : ['cursor' => $cursor]);
            $page = $this->client->get('/vector/queues/failed', $query, $this->adminHeaders())->assertOk()->json();
            self::assertIsArray($page);
            self::assertSame(2, $page['limit']);
            self::assertLessThanOrEqual(2, count($page['data']));
            $seen = [...$seen, ...array_column($page['data'], 'id')];
            $cursor = $page['nextCursor'];
            self::assertSame($cursor !== null, $page['hasMore']);
            $pages++;
        } while ($cursor !== null && $pages < 10);

        self::assertSame(3, $pages);
        self::assertSame($expected, $seen);

        // a list row carries no argument at all; the detail endpoint redacts them
        $first = $this->client->get('/vector/queues/failed', ['queue' => 'qa-mail', 'limit' => '1'], $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($first);
        self::assertArrayNotHasKey('args', $first['data'][0]);
        self::assertSame(SendInvoiceJob::class, $first['data'][0]['class']);
        self::assertSame('2027-01-15T08:00:40Z', $first['data'][0]['failedAt']);
        self::assertStringNotContainsString('TOP_SECRET', $this->client->get('/vector/queues/failed', headers: $this->adminHeaders())->body());
    }

    public function testTheLimitHasADefaultAndAMaximum(): void
    {
        $this->recordFailure('qa-mail', 1_800_000_000);
        $page = $this->client->get('/vector/queues/failed', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($page);
        self::assertSame(25, $page['limit']);

        $this->client->get('/vector/queues/failed', ['limit' => '100'], $this->adminHeaders())->assertOk()->assertJsonPath('limit', 100);
        $this->client->get('/vector/queues/failed', ['limit' => '101'], $this->adminHeaders())->assertValidationError('limit');
        $this->client->get('/vector/queues/failed', ['limit' => '0'], $this->adminHeaders())->assertValidationError('limit');
        $this->client->get('/vector/queues/failed', ['limit' => 'ten'], $this->adminHeaders())->assertValidationError('limit');
    }

    public function testTheFailedListFiltersByQueueClassAndFailedAtRange(): void
    {
        $mail = $this->recordFailure('qa-mail', 1_800_000_100);
        $report = $this->recordFailure('qa-reports', 1_800_000_200);
        $other = $this->recordFailure('qa-reports', 1_800_000_300, \App\Tests\Vector\Queues\Fixtures\OtherJob::class, ['report' => 'q3']);
        $this->recordFailure('qa-elsewhere', 1_800_000_250);

        // no filter: every configured queue, never one the dashboard does not configure
        self::assertSame([$other, $report, $mail], $this->listedIds([]));
        self::assertSame([$other, $report], $this->listedIds(['queue' => 'qa-reports']));
        self::assertSame([$report, $mail], $this->listedIds(['class' => SendInvoiceJob::class]));
        // the range is inclusive at both ends, in RFC 3339
        self::assertSame([$other, $report], $this->listedIds(['failedFrom' => gmdate('Y-m-d\TH:i:s\Z', 1_800_000_200)]));
        self::assertSame([$report, $mail], $this->listedIds(['failedTo' => gmdate('Y-m-d\TH:i:sP', 1_800_000_200)]));
        self::assertSame([$report], $this->listedIds(['failedFrom' => '2027-01-15T08:03:20+00:00', 'failedTo' => '2027-01-15T10:03:20+02:00']));
    }

    public function testBadFiltersAreRefusedWith422(): void
    {
        $headers = $this->adminHeaders();
        $this->client->get('/vector/queues/failed', ['queue' => 'qa-elsewhere'], $headers)->assertValidationError('queue');
        $this->client->get('/vector/queues/failed', ['queue' => 'not a queue!'], $headers)->assertValidationError('queue');
        $this->client->get('/vector/queues/failed', ['class' => 'no spaces allowed'], $headers)->assertValidationError('class');
        $this->client->get('/vector/queues/failed', ['failedFrom' => 'yesterday'], $headers)->assertValidationError('failedFrom');
        $this->client->get('/vector/queues/failed', ['failedTo' => '2027-02-30T00:00:00Z'], $headers)->assertValidationError('failedTo');
        $this->client->get('/vector/queues/failed', ['failedFrom' => '2027-01-02T00:00:00Z', 'failedTo' => '2027-01-01T00:00:00Z'], $headers)->assertValidationError('failedTo');
        $this->client->get('/vector/queues/failed', ['cursor' => 'garbage'], $headers)->assertValidationError('cursor');
        $this->client->get('/vector/queues/failed', ['sort' => 'id'], $headers)->assertValidationError('sort');
    }

    public function testTheDetailRedactsSensitiveArguments(): void
    {
        $id = $this->recordFailure('qa-mail', 1_800_000_000);

        $response = $this->client->get("/vector/queues/failed/{$id}", headers: $this->adminHeaders())
            ->assertOk()
            ->assertJson(['data' => [
                'id' => $id,
                'connection' => null,
                'queue' => 'qa-mail',
                'class' => SendInvoiceJob::class,
                'args' => ['invoiceId' => 7, 'apiToken' => '[redacted]'],
                'attempts' => 3,
                'maxAttempts' => 3,
                'exception' => ['class' => \RuntimeException::class, 'message' => 'The mail server refused the message.', 'trace' => null],
                'failedAt' => '2027-01-15T08:00:00Z',
            ]]);

        self::assertStringNotContainsString('TOP_SECRET', $response->body());
    }

    public function testAJobWhoseClassNoLongerLoadsHasEveryArgumentRedacted(): void
    {
        $id = $this->recordFailure('qa-mail', 1_800_000_000, 'App\Jobs\RemovedLongAgo', ['email' => 'someone@example.com']);

        $this->client->get("/vector/queues/failed/{$id}", headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.args', ['email' => '[redacted]']);
    }

    public function testAnUnknownOrForeignRecordIsNotFound(): void
    {
        $foreign = $this->recordFailure('qa-elsewhere', 1_800_000_000);

        $this->client->get('/vector/queues/failed/' . str_repeat('a', 32), headers: $this->adminHeaders())->assertNotFound();
        $this->client->get('/vector/queues/failed/not-an-id', headers: $this->adminHeaders())->assertNotFound();
        $this->client->get("/vector/queues/failed/{$foreign}", headers: $this->adminHeaders())->assertNotFound();
        $this->client->delete("/vector/queues/failed/{$foreign}", $this->adminHeaders())->assertNotFound();
        self::assertNotNull($this->store()->find($foreign));
    }

    public function testRetryingOnePushesItBackAndForgetsTheRecord(): void
    {
        $id = $this->recordFailure('qa-mail', 1_800_000_000);

        $this->client->post('/vector/queues/failed/retry', ['ids' => [$id]], $this->adminHeaders())
            ->assertOk()
            ->assertJson(['data' => [
                ['id' => $id, 'status' => 'retried', 'class' => SendInvoiceJob::class, 'queue' => 'qa-mail', 'reason' => null],
            ]]);

        self::assertSame([SendInvoiceJob::class], $this->queue->waiting['qa-mail'] ?? []);
        self::assertNull($this->store()->find($id));

        $entry = $this->auditFor($id)[0] ?? null;
        self::assertIsArray($entry);
        self::assertSame('queue:retry', $entry['event']);
        self::assertSame('1', $entry['actor_id']);
        self::assertStringNotContainsString('TOP_SECRET', (string) $entry['changes']);
    }

    public function testRetryingManyReportsEveryIdInOrder(): void
    {
        $first = $this->recordFailure('qa-mail', 1_800_000_000);
        $second = $this->recordFailure('qa-reports', 1_800_000_001);
        $foreign = $this->recordFailure('qa-elsewhere', 1_800_000_002);
        $missing = str_repeat('f', 32);
        $broken = $this->recordFailure('qa-mail', 1_800_000_003, SendInvoiceJob::class, ['invoiceId' => 9]);

        $results = $this->client->post('/vector/queues/failed/retry', ['ids' => [$first, $missing, $second, $foreign, $broken]], $this->adminHeaders())
            ->assertOk()
            ->json();
        self::assertIsArray($results);
        self::assertSame(
            [[$first, 'retried'], [$missing, 'not_found'], [$second, 'retried'], [$foreign, 'not_found'], [$broken, 'failed']],
            array_map(static fn (array $r): array => [$r['id'], $r['status']], $results['data']),
        );
        self::assertIsString($results['data'][4]['reason']);

        self::assertSame(1, $this->queue->size('qa-mail'));
        self::assertSame(1, $this->queue->size('qa-reports'));
        self::assertSame(0, $this->queue->size('qa-elsewhere'));
        // a record on a queue the dashboard does not configure is never retried, and a failed retry keeps its record
        self::assertNotNull($this->store()->find($foreign));
        self::assertNotNull($this->store()->find($broken));
    }

    public function testAFailedRetryNeverEchoesASensitiveArgument(): void
    {
        $id = $this->recordFailure('qa-mail', 1_800_000_000, \App\Tests\Vector\Queues\Fixtures\RejectingTokenJob::class, ['apiToken' => 'sk_live_TOP_SECRET']);

        $response = $this->client->post('/vector/queues/failed/retry', ['ids' => [$id]], $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.status', 'failed');

        self::assertStringNotContainsString('TOP_SECRET', $response->body());
        // kinetis/queue withholds the constructor's message and cause when an argument is #[Sensitive]
        $response->assertJsonPath('data.0.reason', 'Cannot reconstruct "' . \App\Tests\Vector\Queues\Fixtures\RejectingTokenJob::class . '": its constructor threw. The cause is withheld because an argument is marked #[Sensitive].');
        self::assertNotNull($this->store()->find($id));
        $entry = $this->auditFor($id)[0] ?? null;
        self::assertIsArray($entry);
        self::assertStringNotContainsString('TOP_SECRET', (string) $entry['changes']);
    }

    public function testRetryValidatesItsBatch(): void
    {
        $headers = $this->adminHeaders();
        $this->client->post('/vector/queues/failed/retry', [], $headers)->assertValidationError('ids');
        $this->client->post('/vector/queues/failed/retry', ['ids' => []], $headers)->assertValidationError('ids');
        $this->client->post('/vector/queues/failed/retry', ['ids' => 'abc'], $headers)->assertValidationError('ids');
        $this->client->post('/vector/queues/failed/retry', ['ids' => [1, 2]], $headers)->assertValidationError('ids');
        $tooMany = array_map(static fn (int $i): string => str_pad(dechex($i), 32, '0', STR_PAD_LEFT), range(1, 51));
        $this->client->post('/vector/queues/failed/retry', ['ids' => $tooMany], $headers)->assertValidationError('ids');

        // one malformed id refuses the whole batch: the well-formed one beside it is neither retried nor audited
        $id = $this->recordFailure('qa-mail', 1_800_000_000);
        foreach ([str_repeat('not-an-id ', 40), strtoupper($id), $id . "\n", substr($id, 1)] as $malformed) {
            $response = $this->client->post('/vector/queues/failed/retry', ['ids' => [$id, $malformed]], $headers)->assertValidationError('ids');
            self::assertStringNotContainsString('not-an-id', $response->body());
        }
        self::assertNotNull($this->store()->find($id));
        self::assertSame([], $this->auditFor($id));
        self::assertSame(0, $this->queue->size('qa-mail'));
    }

    public function testForgettingDeletesTheRecord(): void
    {
        $id = $this->recordFailure('qa-mail', 1_800_000_000);

        $this->client->delete("/vector/queues/failed/{$id}", $this->adminHeaders())->assertStatus(204);

        self::assertNull($this->store()->find($id));
        $this->client->get("/vector/queues/failed/{$id}", headers: $this->adminHeaders())->assertNotFound();
        self::assertSame('queue:forget', $this->auditFor($id)[0]['event'] ?? null);
    }

    public function testPruningDeletesRecordsOlderThanTheAge(): void
    {
        // 2001 and 2002: older than any record the demo's worker could have made
        $old = $this->recordFailure('qa-mail', 1_000_000_000);
        $older = $this->recordFailure('qa-reports', 1_010_000_000);
        // prune spans every queue, the unwatched one included
        $foreign = $this->recordFailure('qa-elsewhere', 1_005_000_000);
        $recent = $this->recordFailure('qa-mail', time() - 60);

        $this->client->post('/vector/queues/failed/prune', ['days' => 8000], $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.deleted', 3);

        self::assertNull($this->store()->find($old));
        self::assertNull($this->store()->find($older));
        self::assertNull($this->store()->find($foreign));
        self::assertNotNull($this->store()->find($recent));

        $this->client->post('/vector/queues/failed/prune', ['hours' => 200_000], $this->adminHeaders())->assertOk()->assertJsonPath('data.deleted', 0);
        self::assertNotNull($this->store()->find($recent));

        $entry = $this->auditFor('failed')[0] ?? null;
        self::assertIsArray($entry);
        self::assertSame('queue:prune', $entry['event']);
    }

    public function testPruneValidatesItsAge(): void
    {
        $headers = $this->adminHeaders();
        $this->client->post('/vector/queues/failed/prune', [], $headers)->assertValidationError('hours');
        $this->client->post('/vector/queues/failed/prune', ['hours' => 1, 'days' => 1], $headers)->assertValidationError('hours');
        $this->client->post('/vector/queues/failed/prune', ['hours' => 0], $headers)->assertValidationError('hours');
        $this->client->post('/vector/queues/failed/prune', ['days' => -3], $headers)->assertValidationError('days');
        $this->client->post('/vector/queues/failed/prune', ['days' => '3'], $headers)->assertValidationError('days');
        $this->client->post('/vector/queues/failed/prune', ['days' => 36501], $headers)->assertValidationError('days');
    }

    public function testClearingNeedsTheQueueNameAsConfirmation(): void
    {
        $this->queue->push(new SendInvoiceJob(1, 'x'), queue: 'qa-mail');
        $this->queue->push(new SendInvoiceJob(2, 'x'), queue: 'qa-mail');
        $this->queue->push(new SendInvoiceJob(3, 'x'), queue: 'qa-reports');

        $this->client->post('/vector/queues/qa-mail/clear', [], $this->adminHeaders())->assertValidationError('confirm');
        $this->client->post('/vector/queues/qa-mail/clear', ['confirm' => 'QA-MAIL'], $this->adminHeaders())->assertValidationError('confirm');
        $this->client->post('/vector/queues/qa-mail/clear', ['confirm' => 'qa-reports'], $this->adminHeaders())->assertValidationError('confirm');
        self::assertSame(2, $this->queue->size('qa-mail'));

        $this->client->post('/vector/queues/qa-mail/clear', ['confirm' => 'qa-mail'], $this->adminHeaders())
            ->assertOk()
            ->assertJson(['data' => ['queue' => 'qa-mail', 'cleared' => 2]]);

        self::assertSame(0, $this->queue->size('qa-mail'));
        self::assertSame(1, $this->queue->size('qa-reports'));
        $entry = $this->auditFor('qa-mail')[0] ?? null;
        self::assertIsArray($entry);
        self::assertSame('queue:clear', $entry['event']);
    }

    public function testQueueNamesTheDashboardDoesNotConfigureAreRejected(): void
    {
        $this->queue->push(new SendInvoiceJob(1, 'x'), queue: 'qa-elsewhere');

        $this->client->post('/vector/queues/qa-elsewhere/clear', ['confirm' => 'qa-elsewhere'], $this->adminHeaders())->assertNotFound();
        $this->client->post('/vector/queues/default/clear', ['confirm' => 'default'], $this->adminHeaders())->assertNotFound();
        self::assertSame(1, $this->queue->size('qa-elsewhere'));
        $this->client->get('/vector/queues/failed', ['queue' => 'default'], $this->adminHeaders())->assertValidationError('queue');
    }

    public function testAnAnonymousCallGetsVectorsOwnAnswer(): void
    {
        $this->client->get('/vector/queues')->assertStatus(401);
        $this->client->post('/vector/queues/failed/prune', ['days' => 1])->assertStatus(401);
    }

    /**
     * @param array<string, string> $query
     * @return list<string>
     */
    private function listedIds(array $query): array
    {
        $page = $this->client->get('/vector/queues/failed', $query, $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($page);

        return array_column($page['data'], 'id');
    }
}
