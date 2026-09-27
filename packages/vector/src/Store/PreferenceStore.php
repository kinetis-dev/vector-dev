<?php

declare(strict_types=1);

namespace Kinetis\Vector\Store;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\QueryBuilder\Query;

/**
 * `vector_preferences`: per-user UI state the client wants back on
 * another device — column order and visibility per resource, density,
 * theme. Keys are `[a-z0-9.-]`, values JSON up to 16 KiB, at most
 * MAX_KEYS of them per account — every request reads them all.
 */
final readonly class PreferenceStore
{
    public const int MAX_VALUE_BYTES = 16384;
    public const int MAX_KEYS = 500;

    public function __construct(private VectorDatabase $db) {}

    /** @return array<string, mixed> key => value */
    public function all(string $ownerId): array
    {
        $preferences = [];

        foreach ($this->query()->table('vector_preferences')->select('key', 'value')->where('owner_id', '=', $ownerId)->orderBy('key', 'ASC')->get() as $row) {
            $preferences[(string) $row['key']] = json_decode((string) $row['value'], true, 16, JSON_THROW_ON_ERROR);
        }

        return $preferences;
    }

    /** @param array<string, mixed> $values key => value; a null value removes the key */
    public function put(string $ownerId, array $values): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $rows = [];
        $remove = [];

        foreach ($values as $key => $value) {
            if ($value === null) {
                $remove[] = $key;

                continue;
            }

            $rows[] = ['owner_id' => $ownerId, 'key' => $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_at' => $now];
        }

        if ($remove !== []) {
            $this->query()->table('vector_preferences')->where('owner_id', '=', $ownerId)->whereIn('key', $remove)->delete();
        }

        if ($rows !== []) {
            $this->query()->table('vector_preferences')->upsert($rows, ['owner_id', 'key'], ['value', 'updated_at']);
        }
    }

    private function query(): Query
    {
        return $this->db->query();
    }
}
