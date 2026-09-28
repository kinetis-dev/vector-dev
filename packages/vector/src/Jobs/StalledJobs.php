<?php

declare(strict_types=1);

namespace Kinetis\Vector\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Store\JobStore;
use Kinetis\Vector\Store\SettingsStore;

/**
 * The staleness rule for job rows. A job moves its row itself — running,
 * a progress count, done, failed — so a worker that dies leaves the row
 * `running` with nothing to move it on. A running row that has not been
 * updated for the configured number of minutes (the Background jobs
 * settings group, 30 by default) is answered as `stalled`, and reap()
 * marks such rows failed for good.
 */
final readonly class StalledJobs
{
    public const int DEFAULT_MINUTES = 30;

    public function __construct(
        private VectorRegistry $registry,
        private SettingsStore $settings,
        private JobStore $jobs,
    ) {}

    /** The threshold the administrator set, else the default. */
    public function minutes(): int
    {
        $group = $this->registry->settings()['jobs'] ?? null;
        $minutes = $group === null ? null : $this->settings->values($group)['stalledAfterMinutes'] ?? null;

        return is_int($minutes) && $minutes >= 1 ? $minutes : self::DEFAULT_MINUTES;
    }

    /**
     * The row as the admin should read it: `stalled` in place of
     * `running` once the threshold has passed since its last update.
     *
     * @param array<string, mixed> $job a row as JobStore reports it
     * @return array<string, mixed>
     */
    public function mark(array $job): array
    {
        if ($job['status'] === JobStore::RUNNING && is_string($job['updatedAt'] ?? null) && new DateTimeImmutable($job['updatedAt']) < $this->cutoff()) {
            $job['status'] = JobStore::STALLED;
        }

        return $job;
    }

    /** Marks every stalled row failed; how many it changed. */
    public function reap(): int
    {
        return $this->jobs->failStalled($this->cutoff(), $this->minutes());
    }

    private function cutoff(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->sub(new \DateInterval("PT{$this->minutes()}M"));
    }
}
