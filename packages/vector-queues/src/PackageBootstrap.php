<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\DatabaseBridge\ConnectionFactory;
use Kinetis\Queue\QueueContract;
use Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface;
use Kinetis\Vector\Queues\Auth\ViewOnlyQueueDashboardPolicy;

/**
 * Wiring only, once per worker: the watched queue names (VECTOR_QUEUES),
 * the default view-only policy, and the failed-job reads on the database
 * connection kinetis/queue-failed-jobs records on. The endpoints are
 * discovered from their attributes. An application binding
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

        // The same connection SqlFailedJobStore uses, read the way its own bootstrap reads it; a link of its
        // own, opened on first use and closed with the application scope.
        $app->bind(FailedJobBrowser::class, static function (AppScope $app) use ($config): FailedJobBrowser {
            $connection = $config->string('FAILED_JOBS_DB_CONNECTION_NAME', '');

            if ($connection === '') {
                $connection = 'default';
            } else {
                QueueContract::assertValidConnectionName($connection, 'FAILED_JOBS_DB_CONNECTION_NAME');
            }

            $link = ConnectionFactory::fromConfig($config, $connection);
            $app->onDispose($link->close(...));

            return new FailedJobBrowser($link);
        });
    }
}
