<?php

declare(strict_types=1);

namespace Kinetis\Vector\Store;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Registry\SettingsDescriptor;
use Kinetis\Vector\Write\JsonObjects;

/**
 * `vector_settings`: one row per group and key, the value as JSON.
 * Reads are one statement per group; writes upsert every key of the
 * group in one statement. Request-neutral: holds the link only.
 */
final readonly class SettingsStore
{
    public function __construct(private VectorDatabase $db) {}

    /**
     * The group's values: what was saved over the group's defaults. A
     * `Json` or `KeyValue` input's value keeps its JSON objects (a
     * stdClass tree, or a list; a stdClass of strings); every other value
     * reads them as arrays.
     *
     * @return array<string, mixed>
     */
    public function values(SettingsDescriptor $group): array
    {
        $values = $group->defaults();
        $fields = $group->fields();

        foreach ($this->query()->table('vector_settings')->select('key', 'value')->where('group', '=', $group->name())->get() as $row) {
            $key = (string) $row['key'];

            if (array_key_exists($key, $values)) {
                try {
                    $value = json_decode((string) $row['value'], false, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue; // a value this reader cannot decode keeps the group's default rather than failing every page
                }

                $values[$key] = JsonObjects::keep($fields, [$key => $value])[$key];
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $values every field of the group, as JSON values */
    public function save(SettingsDescriptor $group, array $values, string $actor): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $rows = [];

        foreach ($values as $key => $value) {
            $rows[] = [
                'group' => $group->name(),
                'key' => $key,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'updated_by' => $actor,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            $this->query()->table('vector_settings')->upsert($rows, ['group', 'key'], ['value', 'updated_by', 'updated_at']);
        }
    }

    private function query(): Query
    {
        return $this->db->query();
    }
}
