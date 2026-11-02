<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\Queues\Fixtures\FixedPolicy;
use App\Tests\Vector\VectorTestCase;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Queue\QueueInterface;
use Kinetis\QueueFailedJobs\FailedJobRetrier;
use Kinetis\QueueFailedJobs\SqlFailedJobStore;
use Kinetis\Vector\Queues\FailedJobBrowser;
use Kinetis\Vector\Queues\FailedJobsLink;

/**
 * The dashboard with everything a route reads or changes bound to a
 * factory that counts its calls and fails, as a database or a queue
 * backend that is down would: the failed-jobs link, the store, the
 * browser, the retrier and the queue. A refused caller must get its 403
 * without any of them being built — before, a route that took one as a
 * method parameter built it before the policy was asked, so a refused
 * caller got a 500 during an outage. It extends VectorTestCase, not
 * QueuesTestCase, whose setUp() reads the real store.
 */
abstract class RefusedDashboardTestCase extends VectorTestCase
{
    protected const array QUEUES = ['qa-mail', 'qa-reports', 'qa-idle'];

    /** A well-formed id no record has: a route reaching the store would look it up. */
    protected const string ID = '0123456789abcdef0123456789abcdef';

    /** @var array<string, int> each id's builds, by id */
    protected array $built = [];

    abstract protected function policy(): FixedPolicy;

    /** @return array<string, string> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'VECTOR_QUEUES' => implode(',', self::QUEUES)];
    }

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(\Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface::class, $this->policy());

        foreach ([FailedJobsLink::class, SqlFailedJobStore::class, FailedJobBrowser::class, FailedJobRetrier::class, QueueInterface::class] as $id) {
            $this->built[$id] = 0;
            $app->bind($id, function () use ($id): never {
                $this->built[$id]++;

                throw new \RuntimeException("{$id} was built for a refused caller.");
            });
        }
    }

    protected function assertNothingWasBuilt(): void
    {
        self::assertSame(array_fill_keys(array_keys($this->built), 0), $this->built);
    }

    /** Every mutation, each on a path and with a body that would otherwise pass validation. */
    protected function assertEveryMutationIsRefused(): void
    {
        $headers = $this->adminHeaders();

        $this->client->post('/vector/queues/failed/retry', ['ids' => [self::ID]], $headers)->assertStatus(403);
        $this->client->delete('/vector/queues/failed/' . self::ID, $headers)->assertStatus(403);
        $this->client->post('/vector/queues/failed/prune', ['days' => 1], $headers)->assertStatus(403);
        $this->client->post('/vector/queues/qa-mail/clear', ['confirm' => 'qa-mail'], $headers)->assertStatus(403);
        // the policy still answers before the queue name is looked up
        $this->client->post('/vector/queues/qa-elsewhere/clear', ['confirm' => 'qa-elsewhere'], $headers)->assertStatus(403);

        $this->assertNothingWasBuilt();
    }
}
