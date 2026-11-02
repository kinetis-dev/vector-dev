<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\Queues\Fixtures\RecordingLink;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Vector\Auth\ActorContext;
use Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface;
use Kinetis\Vector\Queues\Metrics\JobMetricsStore;

/**
 * A policy whose view() says no: the metrics are a 403 before the query
 * is validated or the database is reached — for a watched queue, an
 * unwatched one and a malformed window alike.
 */
final class QueueMetricsDeniedTest extends QueuesTestCase
{
    private RecordingLink $link;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->link = new RecordingLink();
        $app->instance(JobMetricsStore::class, new JobMetricsStore($this->link));
        $app->instance(QueueDashboardPolicyInterface::class, new class implements QueueDashboardPolicyInterface {
            public function view(ActorContext $actor): bool
            {
                return false;
            }

            public function retry(ActorContext $actor): bool
            {
                return true;
            }

            public function forget(ActorContext $actor): bool
            {
                return true;
            }

            public function prune(ActorContext $actor): bool
            {
                return true;
            }

            public function clear(ActorContext $actor, string $queue): bool
            {
                return true;
            }
        });
    }

    public function testARefusedViewIsA403BeforeAnyRead(): void
    {
        $headers = $this->adminHeaders();

        $this->client->get('/vector/queues/metrics', headers: $headers)->assertStatus(403);
        $this->client->get('/vector/queues/metrics', ['queue' => 'qa-elsewhere'], $headers)->assertStatus(403);
        $this->client->get('/vector/queues/metrics', ['from' => 'yesterday'], $headers)->assertStatus(403);

        self::assertSame([], $this->link->statements);
    }
}
