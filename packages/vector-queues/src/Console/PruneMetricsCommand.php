<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Vector\Queues\Metrics\JobMetricsStore;
use Kinetis\Vector\Queues\Metrics\MetricsClock;

/**
 * Deletes every `vector_queue_metrics` row whose minute began more than
 * `--days=<n>` days ago (DEFAULT_DAYS when absent), and prints how many
 * it deleted. Nothing prunes the table on its own: run this from a
 * scheduler. Discovered from its #[Command] attribute under the
 * package's `extra.kinetis.scan` root.
 *
 * $output and $errors are injectable (defaulting to STDOUT and STDERR)
 * for testability: a #[Command] method takes zero parameters or exactly
 * one CommandArguments.
 */
final readonly class PruneMetricsCommand
{
    public const int DEFAULT_DAYS = 7;

    /** A hundred years, as the failed-job prune endpoint caps its age. */
    private const int MAX_DAYS = 36_500;

    /**
     * Both are typed mixed because PHP has no native resource type.
     *
     * @param resource $output
     * @param resource $errors
     */
    public function __construct(
        private JobMetricsStore $store,
        private MetricsClock $clock,
        private mixed $output = STDOUT,
        private mixed $errors = STDERR,
    ) {}

    #[Command('vector:queues:prune-metrics', description: 'Deletes queue metrics older than --days=<n> (7).')]
    public function run(CommandArguments $arguments): int
    {
        $days = $arguments->hasOption('days') ? ($arguments->option('days') ?? '') : (string) self::DEFAULT_DAYS;

        if (preg_match('/^[1-9]\d{0,4}$/D', $days) !== 1 || (int) $days > self::MAX_DAYS) {
            fwrite($this->errors, '--days needs a whole number of days from 1 to ' . self::MAX_DAYS . ", e.g. --days=7.\n");

            return 1;
        }

        $deleted = $this->store->pruneBefore(JobMetricsStore::bucket($this->clock->now() - (int) $days * 86_400));
        fwrite($this->output, "Deleted {$deleted} queue metric row(s) older than {$days} day(s).\n");

        return 0;
    }
}
