<?php

declare(strict_types=1);

namespace Kinetis\Vector\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;
use Kinetis\Vector\Files\FilePruner;
use Kinetis\Vector\Files\VectorStorage;

/**
 * Deletes uploads no record uses once they are older than the grace
 * period, `--grace-hours=<n>` (24 by default), so an upload in a form not
 * saved yet survives. `--dry-run` lists what would go and deletes
 * nothing. For a scheduler to run now and then; what counts as used is
 * FilePruner's. What it deletes goes to stdout, a refusal to stderr.
 */
final readonly class PruneFilesCommand
{
    public function __construct(
        private FilePruner $pruner,
        private VectorStorage $storage,
        private Output $output,
    ) {}

    #[Command('vector:files:prune', description: 'Deletes uploads no record uses, older than --grace-hours=<n> (24). --dry-run lists them and deletes nothing.')]
    public function prune(CommandArguments $arguments): int
    {
        $hours = $arguments->hasOption('grace-hours') ? ($arguments->option('grace-hours') ?? '') : (string) FilePruner::DEFAULT_GRACE_HOURS;

        if (preg_match('/^[1-9]\d{0,5}$/D', $hours) !== 1) {
            $this->output->error('--grace-hours needs a whole number of hours, 1 or more: --grace-hours=24.');

            return 1;
        }

        $reason = $this->storage->unavailableReason();

        if ($reason !== null) {
            $this->output->error($reason);

            return 1;
        }

        $dryRun = $arguments->hasOption('dry-run');
        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $count = $this->pruner->prune((int) $hours * 3600, $dryRun, function (string $key) use ($verb): void {
            $this->output->line("{$verb} {$key}");
        });

        $this->output->line(match (true) {
            $count === 0 => "No unused uploads older than {$hours} hours.",
            $dryRun => "{$count} unused upload(s) would be deleted; nothing was deleted (--dry-run).",
            default => "{$count} unused upload(s) deleted.",
        });

        return 0;
    }
}
