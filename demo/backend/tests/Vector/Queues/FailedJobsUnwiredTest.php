<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Vector\Queues\FailedJobBrowser;
use Kinetis\Vector\Queues\FailedJobsLink;
use Kinetis\Vector\Queues\QueueDashboard;

/**
 * A queue configured but kinetis/queue-failed-jobs not there, as an
 * application that never installed it: the dashboard is, failed jobs are
 * not. The demo installs the package, so the gate is built the way
 * QueueDashboard::register() builds it where the package's classes are
 * missing. The overview's `failed` is null on every queue rather than a
 * count, the permissions name no failed-job action although the demo's
 * policy allows them all, and every /failed route is the Kernel's own
 * 404 — even for a record that exists — before the failed-jobs link or
 * the browser is built. Clearing and the metrics, which need nothing of
 * that package, still answer.
 */
final class FailedJobsUnwiredTest extends QueuesTestCase
{
    /** @var array<string, int> each id's builds */
    private array $built = [];

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(QueueDashboard::class, new QueueDashboard($app, failedJobsInstalled: false));

        foreach ([FailedJobsLink::class, FailedJobBrowser::class] as $id) {
            $this->built[$id] = 0;
            $app->bind($id, function () use ($id): never {
                $this->built[$id]++;

                throw new \RuntimeException("{$id} was built with failed jobs unwired.");
            });
        }
    }

    public function testTheSchemaHasQueuesButNoFailedJobs(): void
    {
        $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()
            ->assertJsonPath('capabilities.queues', true)
            ->assertJsonPath('capabilities.failedJobs', false);
    }

    public function testTheOverviewHasNoFailedCounts(): void
    {
        $this->recordFailure('qa-mail', 1_800_000_000);
        $this->queue->push(new Fixtures\SendInvoiceJob(1, 'x'), queue: 'qa-mail');

        $this->client->get('/vector/queues', headers: $this->adminHeaders())->assertOk()->assertJson(['data' => [
            ['name' => 'qa-mail', 'size' => 1, 'clearable' => true, 'failed' => null],
            ['name' => 'qa-reports', 'size' => 0, 'clearable' => true, 'failed' => null],
            ['name' => 'qa-idle', 'size' => 0, 'clearable' => true, 'failed' => null],
        ]]);
        $this->assertNothingWasBuilt();
    }

    public function testThePermissionsNameNoFailedJobAction(): void
    {
        self::assertSame(
            '{"view":true,"retry":false,"forget":false,"prune":false,"clear":{"qa-mail":true,"qa-reports":true,"qa-idle":true}}',
            $this->client->get('/vector/queues/permissions', headers: $this->adminHeaders())->assertOk()->body(),
        );
    }

    public function testEveryFailedRouteIsA404AndTheRecordStays(): void
    {
        $id = $this->recordFailure('qa-mail', 1_800_000_000);
        $headers = $this->adminHeaders();

        $this->client->get('/vector/queues/failed', headers: $headers)->assertNotFound();
        $this->client->get("/vector/queues/failed/{$id}", headers: $headers)->assertNotFound();
        $this->client->post('/vector/queues/failed/retry', ['ids' => [$id]], $headers)->assertNotFound();
        $this->client->delete("/vector/queues/failed/{$id}", $headers)->assertNotFound();
        $this->client->post('/vector/queues/failed/prune', ['days' => 1], $headers)->assertNotFound();

        self::assertNotNull($this->store()->find($id), 'nothing retried, forgot or pruned it');
        $this->assertNothingWasBuilt();
    }

    public function testClearingAndTheMetricsStillAnswer(): void
    {
        $this->queue->push(new Fixtures\SendInvoiceJob(1, 'x'), queue: 'qa-mail');
        $headers = $this->adminHeaders();

        $this->client->post('/vector/queues/qa-mail/clear', ['confirm' => 'qa-mail'], $headers)->assertOk()->assertJsonPath('data.cleared', 1);
        $this->client->get('/vector/queues/metrics', headers: $headers)->assertOk();
        $this->assertNothingWasBuilt();
    }

    private function assertNothingWasBuilt(): void
    {
        self::assertSame(array_fill_keys(array_keys($this->built), 0), $this->built);
    }
}
