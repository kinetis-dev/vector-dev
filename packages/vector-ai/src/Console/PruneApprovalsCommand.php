<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;
use Kinetis\Vector\Store\JobStore;
use Kinetis\VectorAi\Actions\RewriteStore;
use Kinetis\VectorAi\Approvals\ApprovalStore;

/**
 * Sweeps `vector_ai_approvals`: marks every pending proposal past its
 * expiry `expired`; fails every proposal still `executing` more than
 * ApprovalStore::EXECUTING_MINUTES after it was approved, whose request
 * did not finish, with the unknown-outcome error (never running it
 * again); then deletes every decided one (executed, rejected, expired or
 * failed) decided more than `--days=<n>` days ago (DEFAULT_DAYS when
 * absent), and prints the counts. A proposal executing for less than that
 * bound is left alone. An approval already refuses an expired proposal,
 * and fails a stale executing one, on its own; nothing deletes the rows on
 * its own: run this from a scheduler. It also deletes the rows of
 * `vector_ai_rewrites`, the record texts a ProposeRewrites run keeps,
 * which its finish() leaves in place, of every job that ended, done
 * included (done with its file, failed, or marked failed as stalled by
 * `vector:jobs:reap`); a job still queued or running keeps them, so a
 * scheduler runs the reap first. The counts go to stdout, a refused
 * --days to stderr.
 */
final readonly class PruneApprovalsCommand
{
    public const int DEFAULT_DAYS = 30;

    /** A hundred years, as Vector's own prune commands cap theirs. */
    private const int MAX_DAYS = 36_500;

    public function __construct(
        private ApprovalStore $approvals,
        private Output $output,
        private RewriteStore $rewrites,
        private JobStore $jobs,
    ) {}

    #[Command('vector-ai:approvals:prune', description: 'Expires stale assistant proposals, fails those whose execution never finished, deletes those decided more than --days=<n> (30) ago, and the rewrite proposal rows of jobs that ended.')]
    public function run(CommandArguments $arguments): int
    {
        $days = $arguments->hasOption('days') ? ($arguments->option('days') ?? '') : (string) self::DEFAULT_DAYS;

        if (preg_match('/^[1-9]\d{0,4}$/D', $days) !== 1 || (int) $days > self::MAX_DAYS) {
            $this->output->error('--days needs a whole number of days from 1 to ' . self::MAX_DAYS . ', e.g. --days=30.');

            return 1;
        }

        $now = ApprovalStore::now();
        $expired = $this->approvals->expireStale($now);
        $failed = $this->approvals->failStale(ApprovalStore::staleBefore());
        $deleted = $this->approvals->deleteDecidedBefore($now->modify("-{$days} days"));
        $this->output->line("Expired {$expired} pending approval(s); failed {$failed} unfinished approval(s); deleted {$deleted} decided approval(s) older than {$days} day(s).");
        $leftover = $this->rewrites->deleteEnded($this->jobs);
        $this->output->line("Deleted {$leftover} rewrite proposal row(s) of jobs that ended.");

        return 0;
    }
}
