<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\VectorTestCase;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Queue\QueueInterface;
use Kinetis\Vector\Queues\FailedJobsLink;
use Kinetis\Vector\Queues\Metrics\JobMetricsStore;

/**
 * An application with no queue backend: QUEUE_CONNECTION_NAME names a
 * connection whose QUEUE_<NAME>_CONNECTION is unset, so kinetis/queue
 * binds no QueueInterface, and no test double binds one either. The
 * queue dashboard is not there: the descriptor says so, every
 * /vector/queues route is the Kernel's own 404 — before the failed-jobs
 * link or the metrics store is built — and the queues card is left off
 * the main dashboard, even for the demo's admin, whom the demo's policy
 * lets do everything.
 */
final class QueuesUnconfiguredTest extends VectorTestCase
{
    /** A well-formed id no record has. */
    private const string ID = '0123456789abcdef0123456789abcdef';

    /** @var array<string, int> each id's builds */
    private array $built = [];

    /** @return array<string, string> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'QUEUE_CONNECTION_NAME' => 'unconfigured', 'VECTOR_QUEUES' => 'qa-mail'];
    }

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        // not parent::registerTestDoubles(): that binds a SyncQueue as the application's queue
        foreach ([FailedJobsLink::class, JobMetricsStore::class] as $id) {
            $this->built[$id] = 0;
            $app->bind($id, function () use ($id): never {
                $this->built[$id]++;

                throw new \RuntimeException("{$id} was built with no queue configured.");
            });
        }
    }

    public function testNoQueueIsBound(): void
    {
        // the premise: were a queue bound, the dashboard would be there
        self::assertFalse($this->app->has(QueueInterface::class));
    }

    public function testTheSchemaSaysTheDashboardIsNotThere(): void
    {
        $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()
            ->assertJsonPath('capabilities.queues', false)
            ->assertJsonPath('capabilities.failedJobs', false);
    }

    public function testEveryRouteIsA404BeforeAnythingIsBuilt(): void
    {
        $headers = $this->adminHeaders();

        $this->client->get('/vector/queues', headers: $headers)->assertNotFound();
        $this->client->get('/vector/queues/permissions', headers: $headers)->assertNotFound();
        $this->client->get('/vector/queues/metrics', headers: $headers)->assertNotFound();
        $this->client->get('/vector/queues/failed', headers: $headers)->assertNotFound();
        $this->client->get('/vector/queues/failed/' . self::ID, headers: $headers)->assertNotFound();
        $this->client->post('/vector/queues/failed/retry', ['ids' => [self::ID]], $headers)->assertNotFound();
        $this->client->delete('/vector/queues/failed/' . self::ID, $headers)->assertNotFound();
        $this->client->post('/vector/queues/failed/prune', ['days' => 1], $headers)->assertNotFound();
        $this->client->post('/vector/queues/qa-mail/clear', ['confirm' => 'qa-mail'], $headers)->assertNotFound();

        self::assertSame(array_fill_keys(array_keys($this->built), 0), $this->built);
    }

    public function testTheQueuesCardIsNotViewable(): void
    {
        $page = $this->client->get('/vector/dashboards/main', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($page);

        self::assertNotContains('queues', array_column($page['data']['widgets'], 'name'));
        $this->client->get('/vector/dashboards/main/widgets/queues', headers: $this->adminHeaders())->assertStatus(403);
    }
}
