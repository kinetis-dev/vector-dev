<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Actions;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\Vector\Store\JobStore;

/**
 * `vector_ai_rewrites`, on Vector's own connection: what one run of
 * ProposeRewrites — one Vector job — made of each record, one row per
 * (job, record key), unique on that pair. A row is the proposed text
 * (PROPOSED), or a record left out (SKIPPED, with why), one whose
 * provider call failed or answered nothing (FAILED, with its category or
 * `empty_answer`), or one not sent once the run stopped calling AI
 * (BUDGET: `budget`, the day's tokens spent; `file_full`, the job's file
 * full; `unavailable`, writing help no longer available to the run).
 * Whatever the status, a record with a row is done: a chunk delivered
 * again calls no provider for it.
 *
 * The rows hold record text until the run has ended: finish() builds
 * the CSV from them, and deleteEnded() removes them once the job is no
 * longer queued or running — done, which Vector marks in the same write
 * that saves the file, or failed — so a save that fails loses nothing
 * before the job ends. Built per request.
 */
final readonly class RewriteStore
{
    public const string TABLE = 'vector_ai_rewrites';

    public const string PROPOSED = 'proposed';

    public const string SKIPPED = 'skipped';

    public const string FAILED = 'failed';

    public const string BUDGET = 'budget';

    public function __construct(private VectorDatabase $db) {}

    /** Whether job $jobId already has a row for $recordKey. */
    public function done(int $jobId, string $recordKey): bool
    {
        return $this->query()->where('job_id', '=', $jobId)->where('record_key', '=', $recordKey)->count() > 0;
    }

    /** Whether job $jobId has any row yet: an earlier chunk of it ran. */
    public function started(int $jobId): bool
    {
        return $this->query()->where('job_id', '=', $jobId)->count() > 0;
    }

    /** The summed byte length of job $jobId's proposals, as stored. */
    public function bytes(int $jobId): int
    {
        $row = $this->query()
            ->selectRaw('COALESCE(SUM(OCTET_LENGTH(proposal)), 0) AS n')
            ->where('job_id', '=', $jobId)
            ->where('status', '=', self::PROPOSED)
            ->first();

        return (int) ($row['n'] ?? 0);
    }

    /** Keeps $text as job $jobId's proposal for $recordKey. */
    public function propose(int $jobId, string $recordKey, string $text, bool $truncated): void
    {
        $this->insert($jobId, $recordKey, self::PROPOSED, null, $text, $truncated);
    }

    /** Records that $recordKey was not proposed: $status SKIPPED, FAILED or BUDGET, $reason why (never a message that could quote data). */
    public function leaveOut(int $jobId, string $recordKey, string $status, string $reason): void
    {
        $this->insert($jobId, $recordKey, $status, $reason, null, false);
    }

    /**
     * Job $jobId's proposals, in the order they were made.
     *
     * @return list<array{key: string, text: string, truncated: bool}>
     */
    public function proposals(int $jobId): array
    {
        $rows = $this->query()
            ->select('record_key', 'proposal', 'truncated')
            ->where('job_id', '=', $jobId)
            ->where('status', '=', self::PROPOSED)
            ->orderBy('id', 'ASC')
            ->get();

        return array_map(static fn (array $r): array => [
            'key' => (string) $r['record_key'],
            'text' => (string) $r['proposal'],
            'truncated' => (int) $r['truncated'] === 1,
        ], $rows);
    }

    /**
     * How many of job $jobId's records ended each way: status => reason => count.
     *
     * @return array<string, array<string, int>>
     */
    public function tally(int $jobId): array
    {
        $rows = $this->query()
            ->select('status', 'reason')
            ->selectRaw('COUNT(*) AS n')
            ->where('job_id', '=', $jobId)
            ->groupBy('status', 'reason')
            ->get();
        $tally = [];

        foreach ($rows as $row) {
            $tally[(string) $row['status']][(string) ($row['reason'] ?? '')] = (int) $row['n'];
        }

        return $tally;
    }

    /** Deletes job $jobId's rows. */
    public function forget(int $jobId): int
    {
        return $this->query()->where('job_id', '=', $jobId)->delete();
    }

    /**
     * Deletes the rows of every job no longer queued or running — done,
     * its CSV saved on the job, or failed or reaped as stalled; how many
     * rows.
     */
    public function deleteEnded(JobStore $jobs): int
    {
        $ids = array_map(static fn (array $r): int => (int) $r['job_id'], $this->query()->select('job_id')->distinct()->get());
        $deleted = 0;

        foreach ($ids as $jobId) {
            $status = $jobs->find($jobId)['status'] ?? null;

            if ($status !== JobStore::QUEUED && $status !== JobStore::RUNNING) {
                $deleted += $this->forget($jobId);
            }
        }

        return $deleted;
    }

    private function insert(int $jobId, string $recordKey, string $status, ?string $reason, ?string $text, bool $truncated): void
    {
        $this->query()->insert([
            'job_id' => $jobId,
            'record_key' => $recordKey,
            'status' => $status,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 40),
            'proposal' => $text,
            'truncated' => $truncated ? 1 : 0,
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        ]);
    }

    private function query(): Query
    {
        return $this->db->query()->table(self::TABLE);
    }
}
