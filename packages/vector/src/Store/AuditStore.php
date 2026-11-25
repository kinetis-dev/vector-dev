<?php

declare(strict_types=1);

namespace Kinetis\Vector\Store;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\QueryBuilder\Query;

/**
 * `vector_audit`: field-level history per record. The writer records
 * what changed, `{field: {from, to}}` on an update, every value on a
 * create (each value as the record serialized it, a JSON object a
 * stdClass when read back), and who did it, impersonator included, and
 * the channel it came through (ActorContext::$origin) when that was not
 * the admin's own hand. A null origin is not written, so an installation that has not
 * yet added the `origin` column keeps recording its own writes.
 *
 * @phpstan-type AuditRow array{id: int, event: string, actorId: string, impersonatorId: string|null, origin: string|null, changes: array<string, mixed>, createdAt: string}
 */
final readonly class AuditStore
{
    /** How deep record() lets the changes nest: json_encode()'s own default. */
    public const int ENCODE_DEPTH = 512;

    public function __construct(private VectorDatabase $db) {}

    /**
     * @param list<string> $recordIds
     * @param array<string, mixed> $changes
     */
    public function record(string $resource, array $recordIds, string $event, array $changes, string $actorId, ?string $impersonatorId, ?string $origin = null): void
    {
        $rows = [];
        $now = JobStore::now();
        $encoded = json_encode($changes, JSON_THROW_ON_ERROR, self::ENCODE_DEPTH);

        foreach ($recordIds as $id) {
            $rows[] = [
                'resource' => $resource,
                'record_id' => $id,
                'actor_id' => $actorId,
                'impersonator_id' => $impersonatorId,
                'event' => $event,
                'changes' => $encoded,
                'created_at' => $now,
            ] + ($origin === null ? [] : ['origin' => $origin]);
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
     * One row as an entry. The changes are read with every JSON object a
     * stdClass, so a value recorded as `{}` or `{"a": {}}` reads back as
     * the object it was, and a list as a list; only the entry's own
     * shape is arrays: field => value on a create, field => `{from, to}`
     * otherwise. json_encode() writes ENCODE_DEPTH levels where
     * json_decode() at that depth reads one fewer, so the read allows one
     * more, and whatever record() stored reads back.
     *
     * @param array<string, mixed> $row
     * @return AuditRow
     */
    private static function entry(array $row): array
    {
        $tree = json_decode((string) $row['changes'], false, self::ENCODE_DEPTH + 1, JSON_THROW_ON_ERROR);
        $changes = [];

        foreach ($tree instanceof \stdClass ? get_object_vars($tree) : [] as $name => $change) {
            $changes[(string) $name] = $row['event'] !== 'create' && $change instanceof \stdClass ? get_object_vars($change) : $change;
        }

        return [
            'id' => (int) $row['id'],
            'event' => (string) $row['event'],
            'actorId' => (string) $row['actor_id'],
            'impersonatorId' => $row['impersonator_id'] === null ? null : (string) $row['impersonator_id'],
            // absent where the column has not been added yet
            'origin' => isset($row['origin']) ? (string) $row['origin'] : null,
            'changes' => $changes,
            'createdAt' => (new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    private function query(): Query
    {
        return $this->db->query();
    }
}
