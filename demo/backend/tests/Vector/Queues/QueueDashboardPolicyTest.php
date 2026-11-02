<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\Queues\Fixtures\SendInvoiceJob;

/**
 * Who may do what. The demo binds DemoQueueDashboardPolicy: an admin may
 * mutate, anyone else is refused — and in the demo, anyone else is
 * already refused at Vector's own door. QueueDashboardDefaultPolicyTest
 * covers an admitted user the policy itself refuses.
 */
final class QueueDashboardPolicyTest extends QueuesTestCase
{
    public function testEveryMutationIsRefusedForANonAdmin(): void
    {
        $id = $this->recordFailure('qa-mail', 1_800_000_000);
        $this->queue->push(new SendInvoiceJob(1, 'x'), queue: 'qa-mail');
        $headers = $this->editorHeaders();

        $this->client->post('/vector/queues/failed/retry', ['ids' => [$id]], $headers)->assertStatus(403);
        $this->client->delete("/vector/queues/failed/{$id}", $headers)->assertStatus(403);
        $this->client->post('/vector/queues/failed/prune', ['days' => 1], $headers)->assertStatus(403);
        $this->client->post('/vector/queues/qa-mail/clear', ['confirm' => 'qa-mail'], $headers)->assertStatus(403);

        self::assertNotNull($this->store()->find($id));
        self::assertSame(1, $this->queue->size('qa-mail'));
        self::assertSame([], $this->auditFor($id));
        self::assertSame([], $this->auditFor('failed'), 'no queue:prune entry');
        self::assertSame([], $this->auditFor('qa-mail'), 'no queue:clear entry');
    }

    /** One application, two callers in turn: the second is judged as itself, not as whoever came before. */
    public function testAnAdminsAllowedCallLeavesNothingBehindForTheNextCaller(): void
    {
        $this->queue->push(new SendInvoiceJob(1, 'x'), queue: 'qa-mail');
        $this->queue->push(new SendInvoiceJob(2, 'x'), queue: 'qa-reports');

        $this->client->post('/vector/queues/qa-mail/clear', ['confirm' => 'qa-mail'], $this->adminHeaders())->assertStatus(200);
        $this->client->post('/vector/queues/qa-reports/clear', ['confirm' => 'qa-reports'], $this->editorHeaders())->assertStatus(403);

        self::assertSame(1, $this->queue->size('qa-reports'));
    }

    public function testAnAdminsPermissionsAllowEveryMutationOnEveryWatchedQueue(): void
    {
        $this->client->get('/vector/queues/permissions', headers: $this->adminHeaders())->assertOk()
            ->assertJsonPath('view', true)
            ->assertJsonPath('retry', true)
            ->assertJsonPath('forget', true)
            ->assertJsonPath('prune', true)
            ->assertJsonPath('clear', ['qa-mail' => true, 'qa-reports' => true, 'qa-idle' => true]);
    }

    public function testEveryMutationIsAllowedForAnAdmin(): void
    {
        $retried = $this->recordFailure('qa-mail', 1_800_000_000);
        $forgotten = $this->recordFailure('qa-mail', 1_800_000_001);
        $this->queue->push(new SendInvoiceJob(1, 'x'), queue: 'qa-reports');
        $headers = $this->adminHeaders();

        $this->client->post('/vector/queues/failed/retry', ['ids' => [$retried]], $headers)->assertOk()->assertJsonPath('data.0.status', 'retried');
        $this->client->delete("/vector/queues/failed/{$forgotten}", $headers)->assertStatus(204);
        $this->client->post('/vector/queues/failed/prune', ['days' => 8000], $headers)->assertOk();
        $this->client->post('/vector/queues/qa-reports/clear', ['confirm' => 'qa-reports'], $headers)->assertOk()->assertJsonPath('data.cleared', 1);
    }
}
