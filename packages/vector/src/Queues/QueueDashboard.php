<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\DatabaseBridge\ConnectionFactory;
use Kinetis\Http\Routing\Exception\RouteNotFoundException;
use Kinetis\QueueFailedJobs\FailedJobRetrier;
use Kinetis\QueueFailedJobs\SqlFailedJobStore;
use Kinetis\Vector\Auth\ActorContext;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\Vector\Jobs\VectorQueue;
use Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface;
use Kinetis\Vector\Queues\Auth\ViewOnlyQueueDashboardPolicy;
use Kinetis\Vector\Queues\Metrics\JobMetricsStore;
use Kinetis\Vector\Queues\Metrics\MetricsClock;
use Kinetis\Vector\Queues\Metrics\MetricsFailureThrottle;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The queue dashboard's wiring and its two gates.
 *
 * {@see self::register()} runs from Vector's own PackageBootstrap, once
 * per worker: the watched queue names (VECTOR_QUEUES), the default
 * view-only policy, the job metrics on Vector's own database connection
 * (VECTOR_DB_CONNECTION, where `vector_jobs` and the rest live), and the
 * failed-job reads on the connection kinetis/queue-failed-jobs records
 * on. The endpoints, the metrics listener and the prune command are
 * discovered from their attributes. An application binding
 * QueueDashboardPolicyInterface in its own bootstrap.php wins over this,
 * since that runs later.
 *
 * The gates are read at request time from explicit AppScope bindings
 * only, so an application's bootstrap.php that binds a queue later is
 * seen, and nothing is built or opened to answer them:
 *
 * - the dashboard is there when the application's queue is bound (the
 *   backend check {@see VectorQueue::applicationQueueBound()} makes for
 *   Vector's own jobs) and the policy lets the actor view; otherwise
 *   every /vector/queues route is the Kernel's own 404;
 * - failed jobs are there when, on top of that, kinetis/queue-failed-jobs
 *   is installed and its store and retrier are bound; otherwise the
 *   /vector/queues/failed routes are that 404 too, and nothing of that
 *   package is touched — it is a suggestion, not a requirement.
 * - the job metrics are there when, on top of the dashboard,
 *   VECTOR_QUEUE_METRICS has not turned them off (unset is on), read once
 *   at boot: off, nothing is recorded, the listener never builds the
 *   metrics store nor the link under it, and /vector/queues/metrics is
 *   that 404 too.
 *
 * Request-neutral: the only state is the application scope.
 */
final readonly class QueueDashboard
{
    /**
     * @param bool $failedJobsInstalled whether kinetis/queue-failed-jobs' classes are installed, decided once at
     *     boot by {@see self::register()}; a test builds one that says no, as an application without it would
     * @param bool $metricsEnabled whether job metrics are recorded and served: VECTOR_QUEUE_METRICS, on unless it says
     *     otherwise, read once at boot by {@see self::register()}
     */
    public function __construct(
        private AppScope $app,
        private bool $failedJobsInstalled,
        private bool $metricsEnabled = true,
    ) {}

    public static function register(AppScope $app, Config $config): void
    {
        // class_exists() asks the autoloader, which loads nothing that is not installed
        // Config::bool(): unset or blank is the default, on; anything it cannot read as a boolean stops the boot
        $app->instance(self::class, new self($app, class_exists(SqlFailedJobStore::class), $config->bool('VECTOR_QUEUE_METRICS', true)));
        $app->instance(QueueNames::class, QueueNames::fromConfig($config));
        $app->bind(QueueDashboardPolicyInterface::class, ViewOnlyQueueDashboardPolicy::class);
        $app->instance(MetricsClock::class, new MetricsClock());
        $app->instance(MetricsFailureThrottle::class, new MetricsFailureThrottle());

        // The metrics are Vector's own table, `vector_queue_metrics`, so they live on Vector's connection, beside
        // `vector_jobs`, and need nothing of kinetis/queue-failed-jobs. Built on first resolve, on the link
        // VectorDatabase resolves once and shares (bind() is shared by default). The store begins no transaction on it.
        $app->bind(JobMetricsStore::class, static function (AppScope $app): JobMetricsStore {
            $vector = $app->get(VectorDatabase::class);

            if (!$vector instanceof VectorDatabase) {
                throw new \LogicException(VectorDatabase::class . ' is bound to something else.');
            }

            return new JobMetricsStore($vector->link());
        });

        // The same connection SqlFailedJobStore uses, on a link of its own: built on first resolve, shared
        // for the worker's lifetime (bind() is shared by default) and closed with the application scope. Only a
        // failed-jobs route resolves it, and only once failedJobsAvailable() said kinetis/queue-failed-jobs is wired.
        //
        // A kinetis/persistence link is safe for concurrent statements from several Fibers in one worker,
        // whichever driver it is. ConnectionFactory::fromConfig() (kinetis/database-bridge v1.4.2
        // src/ConnectionFactory.php:103) takes DB_DRIVER, `auto` when unset, and SqlConnectionFactory::create()
        // (kinetis/persistence v1.4.5 src/SqlConnectionFactory.php:56, :127-136) makes `auto` the native async
        // pool under FrankenPHP or RoadRunner and PDO everywhere else — PHP-FPM and a CLI `queue:work`, as in the
        // demo. Both are safe:
        // - the native clients are pools with one statement in flight per connection, "and callers beyond it
        //   wait for a connection like any pool" (src/Driver/MysqliAsyncClient.php:46-49,
        //   src/Driver/PgsqlAsyncClient.php:50-52), and "a second Fiber running its own queries is served on
        //   its own connection and must keep working" (src/Driver/FiberTransactions.php:14-17);
        // - PDO is "one lazily-opened PDO connection doing native-speed blocking work", where concurrent work
        //   still produces correct results and "the queries simply run sequentially"
        //   (src/Driver/PdoMysqlClient.php:25-28): a blocking statement never suspends its Fiber, so no
        //   other Fiber can interleave a statement on that connection.
        // The browser begins no transaction on it.
        $app->bind(FailedJobsLink::class, static function (AppScope $app) use ($config): FailedJobsLink {
            $link = ConnectionFactory::fromConfig($config, FailedJobsLink::connectionName($config));
            $app->onDispose($link->close(...));

            return new FailedJobsLink($link);
        });

        $app->bind(FailedJobBrowser::class, static function (AppScope $app): FailedJobBrowser {
            $shared = $app->get(FailedJobsLink::class);

            if (!$shared instanceof FailedJobsLink) {
                throw new \LogicException(FailedJobsLink::class . ' is bound to something else.');
            }

            return new FailedJobBrowser($shared->link);
        });
    }

    /** Whether a queue backend is configured to read and clear: the application's own QueueInterface. Nothing is built. */
    public function configured(): bool
    {
        return VectorQueue::applicationQueueBound($this->app);
    }

    /** Whether the dashboard is there for $actor: a queue configured, and the policy letting them view. */
    public function available(QueueDashboardPolicyInterface $policy, ActorContext $actor): bool
    {
        return $this->configured() && $policy->view($actor);
    }

    /**
     * Whether job metrics are recorded and served (VECTOR_QUEUE_METRICS, read at boot). Off, the metrics listener
     * returns before it resolves anything and /vector/queues/metrics is the Kernel's own 404.
     */
    public function metricsEnabled(): bool
    {
        return $this->metricsEnabled;
    }

    /**
     * Whether failed jobs are there for $actor: the dashboard is, and
     * kinetis/queue-failed-jobs is installed with its store and retrier
     * bound.
     */
    public function failedJobsAvailable(QueueDashboardPolicyInterface $policy, ActorContext $actor): bool
    {
        return $this->available($policy, $actor) && $this->failedJobsWired();
    }

    /** kinetis/queue-failed-jobs installed, and its store and retrier bound — by its own bootstrap or the application's. */
    public function failedJobsWired(): bool
    {
        return $this->failedJobsInstalled
            && $this->app->has(SqlFailedJobStore::class)
            && $this->app->has(FailedJobRetrier::class);
    }

    /**
     * The Kernel's own 404 for a path no route matches, which it answers
     * for this exception: an unavailable dashboard is told from a path
     * that was never registered by nothing.
     */
    public static function notFound(ServerRequestInterface $request): RouteNotFoundException
    {
        return RouteNotFoundException::forPath($request->getUri()->getPath());
    }
}
