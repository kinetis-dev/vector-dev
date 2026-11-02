<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\Queues\Fixtures\SendInvoiceJob;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface;
use Kinetis\Vector\Queues\Auth\ViewOnlyQueueDashboardPolicy;

/** The package's own policy, as an application that binds none gets it: anyone Vector admits may look, nobody may change anything. */
final class QueueDashboardDefaultPolicyTest extends QueuesTestCase
{
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(QueueDashboardPolicyInterface::class, new ViewOnlyQueueDashboardPolicy());
    }

    public function testAnAdmittedUserMayViewButNotMutate(): void
    {
        $id = $this->recordFailure('qa-mail', 1_800_000_000);
        $this->queue->push(new SendInvoiceJob(1, 'x'), queue: 'qa-mail');
        $headers = $this->adminHeaders();

        $this->client->get('/vector/queues', headers: $headers)->assertOk();
        $this->client->get('/vector/queues/failed', headers: $headers)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->client->get("/vector/queues/failed/{$id}", headers: $headers)->assertOk();

        $this->client->post('/vector/queues/failed/retry', ['ids' => [$id]], $headers)->assertStatus(403);
        $this->client->delete("/vector/queues/failed/{$id}", $headers)->assertStatus(403);
        $this->client->post('/vector/queues/failed/prune', ['days' => 1], $headers)->assertStatus(403);
        $this->client->post('/vector/queues/qa-mail/clear', ['confirm' => 'qa-mail'], $headers)->assertStatus(403);

        self::assertNotNull($this->store()->find($id));
        self::assertSame(1, $this->queue->size('qa-mail'));
        self::assertSame([], $this->queue->waiting['qa-reports'] ?? []);
        self::assertSame([], $this->auditFor($id));
    }
}
