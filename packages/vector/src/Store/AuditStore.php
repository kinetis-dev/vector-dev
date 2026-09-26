<?php

declare(strict_types=1);

namespace Kinetis\Vector\Store;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\QueryBuilder\Query;

/**
 * `vector_audit`: field-level history per record. The writer records
 * what changed — `{field: {from, to}}` on an update, every value on a
 * create — and who did it, impersonator included.
 *
 * @phpstan-type AuditRow array{id: int, event: string, actorId: string, impersonatorId: string|null, changes: array<string, mixed>, createdAt: string}
 */
final readonly class AuditStore
{
    public function __construct(private VectorDatabase $db) {}

    /**
     * @param list<string> $recordIds
     * @param array<string, mixed> $changes
     */
    public function record(string $resource, array $recordIds, string $event, array $changes, string $actorId, ?string $impersonatorId): void
    {
        $rows = [];
        $now = JobStore::now();
        $encoded = json_encode($changes, JSON_THROW_ON_ERROR);

        foreach ($recordIds as $id) {
            $rows[] = [
                'resource' => $resource,
                'record_id' => $id,
                'actor_id' => $actorId,
                'impersonator_id' => $impersonatorId,
                'event' => $event,
                'changes' => $encoded,
                'created_at' => $now,
            ];
        }

        if ($rows !== []) {
            $this->query()->table('vector_audit')->insert($rows);
        }
    }

    /** @return list<AuditRow> newest first */
    public function trail(string $resource, string $recordId, int $limit = 100): array
    {
        $rows = $this->query()->table('vector_audit')
            ->where('resource', '=', $resource)
            ->where('record_id', '=', $recordId)
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get();

        return array_map(self::entry(...), $rows);
    }

    /** @return AuditRow|null one entry of one record's trail */
    public function find(string $resource, string $recordId, int $entryId): ?array
    {
        $row = $this->query()->table('vector_audit')
            ->where('id', '=', $entryId)
            ->where('resource', '=', $resource)
            ->where('record_id', '=', $recordId)
            ->first();

        return is_array($row) ? self::entry($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return AuditRow
     */
    private static function entry(array $row): array
    {
        $changes = json_decode((string) $row['changes'], true, 16, JSON_THROW_ON_ERROR);

        return [
            'id' => (int) $row['id'],
            'event' => (string) $row['event'],
            'actorId' => (string) $row['actor_id'],
            'impersonatorId' => $row['impersonator_id'] === null ? null : (string) $row['impersonator_id'],
            'changes' => is_array($changes) ? $changes : [],
            'createdAt' => (new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    private function query(): Query
    {
        return $this->db->query();
    }
}
