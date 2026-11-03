<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\Queues\Fixtures\ClearableRecordingQueue;
use App\Tests\Vector\VectorTestCase;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Queue\QueueInterface;
use Kinetis\Vector\Queues\FailedJobBrowser;
use Kinetis\Vector\Queues\FailedJobsLink;

/**
 * Vector booted where kinetis/queue-failed-jobs is not installed at all,
 * as an application that skips the suggestion: the CI job
 * `php-without-failed-jobs` removes the package from the demo and runs
 * this test alone. With the package installed, as in the demo itself, it
 * skips — FailedJobsUnwiredTest covers "installed but not wired" there.
 *
 * Unlike FailedJobsUnwiredTest, nothing here builds the gate: the
 * application boots as it would in production, so QueueDashboard::register()
 * decides by itself that the package is missing. Any hard reference to one
 * of its classes on the boot or request path fails here — as a boot error,
 * or as a 500 instead of the answers below. This file names the package's
 * classes only as the ::class string the skip check takes, which loads
 * nothing; it must not extend QueuesTestCase, whose setUp() builds
 * SqlFailedJobStore.
 */
final class FailedJobsNotInstalledTest extends VectorTestCase
{
    /** A well-formed id no record has. */
    private const string ID = '0123456789abcdef0123456789abcdef';

    private ClearableRecordingQueue $queue;

    /** @var array<string, int> each id's builds */
    private array $built = [];

    /** @return array<string, string> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'VECTOR_QUEUES' => 'qa-mail'];
    }

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        // not parent::registerTestDoubles(): a clearable queue that runs nothing, as QueuesTestCase binds
        $this->queue = new ClearableRecordingQueue();
        $app->instance(QueueInterface::class, $this->queue);

        foreach ([FailedJobsLink::class, FailedJobBrowser::class] as $id) {
            $this->built[$id] = 0;
            $app->bind($id, function () use ($id): never {
                $this->built[$id]++;

                throw new \RuntimeException("{$id} was built without kinetis/queue-failed-jobs.");
            });
        }
    }

    protected function setUp(): void
    {
        // booted first, so VectorTestCase::tearDown(), which runs after a skip too, has its application
        parent::setUp();

        // ::class is a compile-time string: class_exists() is the only thing here that asks the autoloader
        if (class_exists(\Kinetis\QueueFailedJobs\SqlFailedJobStore::class)) {
            self::markTestSkipped('kinetis/queue-failed-jobs is installed; the CI job php-without-failed-jobs removes it.');
        }
    }

    public function testTheSchemaHasQueuesButNoFailedJobs(): void
    {
        $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()
            ->assertJsonPath('capabilities.queues', true)
            ->assertJsonPath('capabilities.failedJobs', false);
    }

    public function testTheQueueListAnswersWithNoFailedCounts(): void
    {
        $this->queue->push(new Fixtures\SendInvoiceJob(1, 'x'), queue: 'qa-mail');

        $this->client->get('/vector/queues', headers: $this->adminHeaders())->assertOk()->assertJson(['data' => [
            ['name' => 'qa-mail', 'size' => 1, 'clearable' => true, 'failed' => null],
        ]]);
        $this->assertNothingWasBuilt();
    }

    public function testThePermissionsNameNoFailedJobAction(): void
    {
        self::assertSame(
            '{"view":true,"retry":false,"forget":false,"prune":false,"clear":{"qa-mail":true}}',
            $this->client->get('/vector/queues/permissions', headers: $this->adminHeaders())->assertOk()->body(),
        );
    }

    public function testEveryFailedRouteIsA404(): void
    {
        $headers = $this->adminHeaders();

        $this->client->get('/vector/queues/failed', headers: $headers)->assertNotFound();
        $this->client->get('/vector/queues/failed/' . self::ID, headers: $headers)->assertNotFound();
        $this->client->post('/vector/queues/failed/retry', ['ids' => [self::ID]], $headers)->assertNotFound();
        $this->client->delete('/vector/queues/failed/' . self::ID, $headers)->assertNotFound();
        $this->client->post('/vector/queues/failed/prune', ['days' => 1], $headers)->assertNotFound();
        $this->assertNothingWasBuilt();
    }

    private function assertNothingWasBuilt(): void
    {
        self::assertSame(array_fill_keys(array_keys($this->built), 0), $this->built);
    }
}
