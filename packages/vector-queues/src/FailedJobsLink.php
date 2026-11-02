<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues;

use Kinetis\Config\Config;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Queue\QueueContract;

/**
 * The package's one link to the database connection
 * kinetis/queue-failed-jobs records on, FAILED_JOBS_DB_CONNECTION_NAME,
 * shared by the failed-job reads ({@see FailedJobBrowser}) and the job
 * metrics ({@see Metrics\JobMetricsStore}). Built by
 * {@see PackageBootstrap} on first resolve and closed with the
 * application scope.
 */
final readonly class FailedJobsLink
{
    public function __construct(public MysqlLink|PostgresLink $link) {}

    /**
     * The connection name, read the way kinetis/queue-failed-jobs' own
     * bootstrap reads it: `default` when unset or blank, any other name
     * checked against kinetis/queue's connection-name grammar.
     */
    public static function connectionName(Config $config): string
    {
        $connection = $config->string('FAILED_JOBS_DB_CONNECTION_NAME', '');

        if ($connection === '') {
            return 'default';
        }

        QueueContract::assertValidConnectionName($connection, 'FAILED_JOBS_DB_CONNECTION_NAME');

        return $connection;
    }
}
