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

    /** A refused caller learns nothing about which queues exist: the policy answers before the queue name is looked up. */
    public function testARefusedClearOfAnUnconfiguredQueueIsA403NotA404(): void
    {
        $this->client->post('/vector/queues/qa-elsewhere/clear', ['confirm' => 'qa-elsewhere'], $this->adminHeaders())->assertStatus(403);
        $this->client->post('/vector/queues/qa-mail/clear', ['confirm' => 'qa-mail'], $this->adminHeaders())->assertStatus(403);
    }

    /** A segment no queue anywhere could be named is a 404 before the policy is asked, so a policy only ever sees a well-formed name; the 404 depends on the grammar alone, not on which queues are watched. */
    public function testAClearOfAMalformedQueueNameIsA404BeforeThePolicy(): void
    {
        foreach (['qa.mail', str_repeat('q', 81), 'qa%20mail'] as $segment) {
            $this->client->post("/vector/queues/{$segment}/clear", ['confirm' => $segment], $this->adminHeaders())->assertStatus(404);
        }

        $this->client->post('/vector/queues/' . str_repeat('q', 80) . '/clear', ['confirm' => str_repeat('q', 80)], $this->adminHeaders())->assertStatus(403);
    }
}
