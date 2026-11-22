<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;
use Kinetis\Vector\Queues\Metrics\JobMetricsStore;
use Kinetis\Vector\Queues\Metrics\MetricsClock;

/**
 * Deletes every `vector_queue_metrics` row whose minute began more than
 * `--days=<n>` days ago (DEFAULT_DAYS when absent), and prints how many
 * it deleted. Nothing prunes the table on its own: run this from a
 * scheduler. Discovered from its #[Command] attribute under the
 * `extra.kinetis.scan` root, Kinetis\Vector\. The count goes to
 * stdout, a refused --days to stderr.
 */
final readonly class PruneMetricsCommand
{
    public const int DEFAULT_DAYS = 7;

    /** A hundred years, as the failed-job prune endpoint caps its age. */
    private const int MAX_DAYS = 36_500;

    public function __construct(
        private JobMetricsStore $store,
        private MetricsClock $clock,
        private Output $output,
    ) {}

    #[Command('vector:queues:prune-metrics', description: 'Deletes queue metrics older than --days=<n> (7).')]
    public function run(CommandArguments $arguments): int
    {
        $days = $arguments->hasOption('days') ? ($arguments->option('days') ?? '') : (string) self::DEFAULT_DAYS;

        if (preg_match('/^[1-9]\d{0,4}$/D', $days) !== 1 || (int) $days > self::MAX_DAYS) {
            $this->output->error('--days needs a whole number of days from 1 to ' . self::MAX_DAYS . ', e.g. --days=7.');

            return 1;
        }

        $deleted = $this->store->pruneBefore(JobMetricsStore::bucket($this->clock->now() - (int) $days * 86_400));
        $this->output->line("Deleted {$deleted} queue metric row(s) older than {$days} day(s).");

        return 0;
    }
}
