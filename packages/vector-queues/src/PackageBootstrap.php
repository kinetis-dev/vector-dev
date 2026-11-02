<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\DatabaseBridge\ConnectionFactory;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface;
use Kinetis\Vector\Queues\Auth\ViewOnlyQueueDashboardPolicy;
use Kinetis\Vector\Queues\Metrics\JobMetricsStore;
use Kinetis\Vector\Queues\Metrics\MetricsClock;
use Kinetis\Vector\Queues\Metrics\MetricsFailureThrottle;

/**
 * Wiring only, once per worker: the watched queue names (VECTOR_QUEUES),
 * the default view-only policy, and the failed-job reads and job metrics
 * on the database connection kinetis/queue-failed-jobs records on. The
 * endpoints, the metrics listener and the prune command are discovered
 * from their attributes. An application binding
 * QueueDashboardPolicyInterface in its own bootstrap.php wins over this,
 * since that runs later.
 */
final readonly class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        $app->instance(QueueNames::class, QueueNames::fromConfig($config));
        $app->bind(QueueDashboardPolicyInterface::class, ViewOnlyQueueDashboardPolicy::class);
        $app->instance(MetricsClock::class, new MetricsClock());
        $app->instance(MetricsFailureThrottle::class, new MetricsFailureThrottle());

        // The same connection SqlFailedJobStore uses, on a link of its own: built on first resolve, shared
        // for the worker's lifetime (bind() is shared by default) and closed with the application scope.
        //
        // One link serves both FailedJobBrowser and JobMetricsStore, deliberately. A kinetis/persistence
        // link (v1.4.5; the paths below are under its src/) is safe for concurrent statements from several Fibers in one worker: the async drivers are
        // pools that put one statement in flight per connection and make a Fiber beyond maxConnections wait
        // for one (Driver/MysqliAsyncClient.php:46-49, Driver/PgsqlAsyncClient.php:50-52), the per-Fiber
        // transaction guard exists precisely because "a second Fiber running its own queries is served on
        // its own connection and must keep working" (Driver/FiberTransactions.php:14-17), and the PDO
        // fallback never suspends mid-statement, so overlapping work simply runs in turn
        // (Driver/PdoMysqlClient.php:25-28). Neither reader nor the metrics store begins a transaction on
        // it. A second link would only double the connections the worker holds open.
        $app->bind(FailedJobsLink::class, static function (AppScope $app) use ($config): FailedJobsLink {
            $link = ConnectionFactory::fromConfig($config, FailedJobsLink::connectionName($config));
            $app->onDispose($link->close(...));

            return new FailedJobsLink($link);
        });

        $app->bind(FailedJobBrowser::class, static fn (AppScope $app): FailedJobBrowser => new FailedJobBrowser(self::link($app)));
        $app->bind(JobMetricsStore::class, static fn (AppScope $app): JobMetricsStore => new JobMetricsStore(self::link($app)));
    }

    private static function link(AppScope $app): MysqlLink|PostgresLink
    {
        $shared = $app->get(FailedJobsLink::class);

        if (!$shared instanceof FailedJobsLink) {
            throw new \LogicException(FailedJobsLink::class . ' is bound to something else.');
        }

        return $shared->link;
    }
}
