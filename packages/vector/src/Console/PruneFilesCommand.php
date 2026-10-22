<?php

declare(strict_types=1);

namespace Kinetis\Vector\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Vector\Files\FilePruner;
use Kinetis\Vector\Files\VectorStorage;

/**
 * Deletes uploads no record uses once they are older than the grace
 * period, `--grace-hours=<n>` (24 by default), so an upload in a form not
 * saved yet survives. `--dry-run` lists what would go and deletes
 * nothing. For a scheduler to run now and then; what counts as used is
 * FilePruner's.
 *
 * $output and $errors are injectable (defaulting to STDOUT and STDERR)
 * for testability, as ListCommand's output is.
 */
final readonly class PruneFilesCommand
{
    /**
     * @param resource $output mixed, not resource, since PHP has no native
     *     "resource" type and a readonly property requires one
     * @param resource $errors the same, for refusals
     */
    public function __construct(
        private FilePruner $pruner,
        private VectorStorage $storage,
        private mixed $output = STDOUT,
        private mixed $errors = STDERR,
    ) {}

    #[Command('vector:files:prune', description: 'Deletes uploads no record uses, older than --grace-hours=<n> (24). --dry-run lists them and deletes nothing.')]
    public function prune(CommandArguments $arguments): int
    {
        $hours = $arguments->hasOption('grace-hours') ? ($arguments->option('grace-hours') ?? '') : (string) FilePruner::DEFAULT_GRACE_HOURS;

        if (preg_match('/^[1-9][0-9]{0,5}$/D', $hours) !== 1) {
            fwrite($this->errors, "--grace-hours needs a whole number of hours, 1 or more: --grace-hours=24.\n");

            return 1;
        }

        $reason = $this->storage->unavailableReason();

        if ($reason !== null) {
            fwrite($this->errors, $reason . "\n");

            return 1;
        }

        $dryRun = $arguments->hasOption('dry-run');
        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $count = $this->pruner->prune((int) $hours * 3600, $dryRun, function (string $key) use ($verb): void {
            fwrite($this->output, "{$verb} {$key}\n");
        });

        fwrite($this->output, match (true) {
            $count === 0 => "No unused uploads older than {$hours} hours.\n",
            $dryRun => "{$count} unused upload(s) would be deleted; nothing was deleted (--dry-run).\n",
            default => "{$count} unused upload(s) deleted.\n",
        });

        return 0;
    }
}
