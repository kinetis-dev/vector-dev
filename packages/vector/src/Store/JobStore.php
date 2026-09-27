<?php

declare(strict_types=1);

namespace Kinetis\Vector\Store;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\QueryBuilder\Query;

/**
 * `vector_jobs`: the row a background job reports into. The queue holds
 * the job itself; this row is what the admin who started it polls, and
 * where an export leaves its file — base64, so one text-safe column
 * serves MySQL and PostgreSQL alike. Request-neutral: holds the link only.
 *
 * @phpstan-type JobRow array{id: int, kind: string, resource: string, name: string, actorId: string, status: string, total: int|null, processed: int, result: array<string, mixed>|null, failure: string|null, hasFile: bool, fileName: string|null, createdAt: string, updatedAt: string, finishedAt: string|null}
 */
final readonly class JobStore
{
    public const string QUEUED = 'queued';
    public const string RUNNING = 'running';
    public const string DONE = 'done';
    public const string FAILED = 'failed';

    /** The most a job's file may hold: 12 MiB of file is 16 MiB of base64, MySQL's `MEDIUMBLOB`. */
    public const int MAX_FILE_BYTES = 12 * 1024 * 1024;

    public function __construct(private VectorDatabase $db) {}

    /** @param 'action'|'export'|'import' $kind */
    public function create(string $kind, string $resource, string $name, string $actorId, ?int $total = null, ?string $file = null, ?string $fileName = null, ?string $fileType = null): int
    {
        $now = self::now();

        return (int) $this->query()->table('vector_jobs')->insertGetId([
            'kind' => $kind,
            'resource' => $resource,
            'name' => $name,
            'actor_id' => $actorId,
            'status' => self::QUEUED,
            'total' => $total,
            'processed' => 0,
            'result' => null,
            'failure' => null,
            'file' => $file === null ? null : self::encoded($file),
            'file_name' => $fileName,
            'file_type' => $fileType,
            'created_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,
        ]);
    }

    /** @return JobRow|null */
    public function find(int $id): ?array
    {
        $row = $this->query()->table('vector_jobs')
            ->select('id', 'kind', 'resource', 'name', 'actor_id', 'status', 'total', 'processed', 'result', 'failure', 'file_name', 'created_at', 'updated_at', 'finished_at')
            ->selectRaw('CASE WHEN file IS NULL THEN 0 ELSE 1 END AS has_file')
            ->where('id', '=', $id)
            ->first();

        return is_array($row) ? $this->job($row) : null;
    }

    /** @return list<JobRow> the actor's most recent jobs */
    public function forActor(string $actorId, int $limit = 50): array
    {
        $rows = $this->query()->table('vector_jobs')
            ->select('id', 'kind', 'resource', 'name', 'actor_id', 'status', 'total', 'processed', 'result', 'failure', 'file_name', 'created_at', 'updated_at', 'finished_at')
            ->selectRaw('CASE WHEN file IS NULL THEN 0 ELSE 1 END AS has_file')
            ->where('actor_id', '=', $actorId)
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get();

        return array_map($this->job(...), $rows);
    }

    /** @return array{contents: string, name: string, type: string}|null */
    public function file(int $id): ?array
    {
        $row = $this->query()->table('vector_jobs')->select('file', 'file_name', 'file_type')->where('id', '=', $id)->first();

        if (!is_array($row) || $row['file'] === null) {
            return null;
        }

        $contents = base64_decode((string) $row['file'], true);

        if ($contents === false) {
            return null;
        }

        return ['contents' => $contents, 'name' => (string) ($row['file_name'] ?? 'download'), 'type' => (string) ($row['file_type'] ?? 'application/octet-stream')];
    }

    /**
     * Marks a queued job running: true when this call made the
     * transition, false when the row was not queued — already running,
     * done or failed — which is how a redelivered job knows not to run
     * again. One conditional update, so two workers cannot both win.
     */
    public function start(int $id, ?int $total = null): bool
    {
        $values = ['status' => self::RUNNING, 'updated_at' => self::now()];

        if ($total !== null) {
            $values['total'] = $total;
        }

        return $this->query()->table('vector_jobs')->where('id', '=', $id)->where('status', '=', self::QUEUED)->update($values) === 1;
    }

    public function progress(int $id, int $processed, ?int $total = null): void
    {
        $values = ['processed' => $processed, 'updated_at' => self::now()];

        if ($total !== null) {
            $values['total'] = $total;
        }

        $this->query()->table('vector_jobs')->where('id', '=', $id)->update($values);
    }

    /** @param array<string, mixed> $result */
    public function finish(int $id, array $result, ?int $processed = null, ?string $file = null, ?string $fileName = null, ?string $fileType = null): void
    {
        $now = self::now();
        $values = ['status' => self::DONE, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => $now, 'finished_at' => $now];

        if ($processed !== null) {
            $values['processed'] = $processed;
        }

        if ($file !== null) {
            $values['file'] = self::encoded($file);
            $values['file_name'] = $fileName;
            $values['file_type'] = $fileType;
        }

        $this->query()->table('vector_jobs')->where('id', '=', $id)->update($values);
    }

    public function fail(int $id, string $failure): void
    {
        $now = self::now();
        $this->query()->table('vector_jobs')->where('id', '=', $id)->update(['status' => self::FAILED, 'failure' => mb_substr($failure, 0, 2000), 'updated_at' => $now, 'finished_at' => $now]);
    }

    /** Drops the stored upload once a job consumed it, keeping the row small. */
    public function clearFile(int $id): void
    {
        $this->query()->table('vector_jobs')->where('id', '=', $id)->update(['file' => null, 'file_name' => null, 'file_type' => null]);
    }

    /**
     * @param array<string, mixed> $row
     * @return JobRow
     */
    private function job(array $row): array
    {
        $result = $row['result'] === null ? null : json_decode((string) $row['result'], true, 16, JSON_THROW_ON_ERROR);

        return [
            'id' => (int) $row['id'],
            'kind' => (string) $row['kind'],
            'resource' => (string) $row['resource'],
            'name' => (string) $row['name'],
            'actorId' => (string) $row['actor_id'],
            'status' => (string) $row['status'],
            'total' => $row['total'] === null ? null : (int) $row['total'],
            'processed' => (int) $row['processed'],
            'result' => is_array($result) ? $result : null,
            'failure' => $row['failure'] === null ? null : (string) $row['failure'],
            'hasFile' => (int) $row['has_file'] === 1,
            'fileName' => $row['file_name'] === null ? null : (string) $row['file_name'],
            'createdAt' => self::iso((string) $row['created_at']),
            'updatedAt' => self::iso((string) $row['updated_at']),
            'finishedAt' => $row['finished_at'] === null ? null : self::iso((string) $row['finished_at']),
        ];
    }

    /** @throws \LengthException when the file is larger than the column holds */
    private static function encoded(string $file): string
    {
        if (strlen($file) > self::MAX_FILE_BYTES) {
            throw new \LengthException(sprintf('The file is %.1f MiB; a job file holds at most %d MiB. Narrow the selection.', strlen($file) / 1048576, self::MAX_FILE_BYTES / 1048576));
        }

        return base64_encode($file);
    }

    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private static function iso(string $stored): string
    {
        return (new DateTimeImmutable($stored, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function query(): Query
    {
        return $this->db->query();
    }
}
