<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Vector\Auth\ActorContext;
use Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface;
use Kinetis\Vector\Queues\Metrics\JobMetricsStore;

/**
 * A policy whose view() says no: the dashboard is not there for this
 * caller, so the metrics are the Kernel's own 404 before the query is
 * validated, and before the metrics store — or the link it is built on —
 * is even constructed: for a watched queue, an unwatched one and a
 * malformed window alike. The store's factory counts its calls and
 * fails, as a database whose configuration is broken would.
 */
final class QueueMetricsDeniedTest extends QueuesTestCase
{
    private int $storesBuilt = 0;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->bind(JobMetricsStore::class, function (): JobMetricsStore {
            $this->storesBuilt++;

            throw new \RuntimeException('The metrics store was built for a refused caller.');
        });
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

    public function testARefusedViewIsA404BeforeAnyRead(): void
    {
        $headers = $this->adminHeaders();

        $this->client->get('/vector/queues/metrics', headers: $headers)->assertStatus(404);
        $this->client->get('/vector/queues/metrics', ['queue' => 'qa-elsewhere'], $headers)->assertStatus(404);
        $this->client->get('/vector/queues/metrics', ['from' => 'yesterday'], $headers)->assertStatus(404);

        self::assertSame(0, $this->storesBuilt);
    }
}
