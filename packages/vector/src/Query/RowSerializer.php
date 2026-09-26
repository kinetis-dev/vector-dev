<?php

declare(strict_types=1);

namespace Kinetis\Vector\Query;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Vector\Registry\ResourceDescriptor;

/**
 * Turns a database row into the record a client sees: every field
 * converted by its compiled value type, relation columns replaced by the
 * display values the service loaded in bulk.
 */
final readonly class RowSerializer
{
    /**
     * @param array<string, mixed> $row
     * @param list<string> $fieldNames the fields to include
     * @param array<string, array<int|string, mixed>> $relations field name => (key => display value)
     * @return array<string, mixed>
     */
    public function serialize(ResourceDescriptor $resource, array $row, array $fieldNames, array $relations): array
    {
        $record = [];

        foreach ($fieldNames as $name) {
            $field = $resource->field($name);

            if ($field === null) {
                continue;
            }

            $record[$name] = match ($field['type']) {
                'belongsTo' => $this->belongsTo($field, $row, $relations[$name] ?? []),
                'hasOne' => $relations[$name][$this->key($row, $resource)] ?? null,
                'hasMany', 'manyToMany' => ['count' => (int) ($relations[$name][$this->key($row, $resource)] ?? 0)],
                'keyValue' => self::keyValue($row[$field['column']] ?? null),
                default => $this->scalar($field, $row[$field['column']] ?? null),
            };
        }

        $soft = $resource->softDelete();

        if ($soft !== null) {
            $record['_trashed'] = ($row[$soft['column']] ?? null) !== null;
        }

        // the optimistic-lock token a client sends back (If-Match, a change's version)
        if (($version = $resource->versionColumn()) !== null && array_key_exists($version, $row)) {
            $record['_version'] = (int) $row[$version];
        }

        return $record;
    }

    /** @param array<string, mixed> $row */
    public function key(array $row, ResourceDescriptor $resource): int|string
    {
        $key = $row[$resource->primaryKeyColumn()];

        return $resource->primaryKeyType() === 'int' ? (int) $key : (string) $key;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $row
     * @param array<int|string, mixed> $labels target id => ['label' => title, carried field => value…]
     * @return array<string, mixed>|null `{id, label}`, plus the columns the relation carries
     */
    private function belongsTo(array $field, array $row, array $labels): ?array
    {
        $key = $row[$field['column']] ?? null;

        if ($key === null) {
            return null;
        }

        $id = $field['valueType'] === 'int' ? (int) $key : (string) $key;
        $related = $labels[$id] ?? [];

        // the carried columns (a related currency) follow the label
        return ['id' => $id, 'label' => (string) ($related['label'] ?? $id), ...array_diff_key($related, ['label' => true])];
    }

    /**
     * The stored JSON as an object of text values — an object even when
     * empty or keyed by digits; a nested value reads as its JSON text.
     */
    private static function keyValue(mixed $stored): ?\stdClass
    {
        $decoded = is_string($stored) ? json_decode($stored, true) : null;

        if (!is_array($decoded)) {
            return null;
        }

        $map = new \stdClass();

        foreach ($decoded as $key => $value) {
            $map->{(string) $key} = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                $value === null => '',
                default => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            };
        }

        return $map;
    }

    /** @param array<string, mixed> $field */
    private function scalar(array $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field['valueType'] ?? 'string') {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => \Kinetis\Vector\Database\Sql::bool($value),
            'timestamp' => $this->timestamp((string) $value, $field['type'] === 'date'),
            // a DATE column reads back as YYYY-MM-DD on both dialects
            'date' => substr((string) $value, 0, 10),
            default => is_string($value) ? $value : (string) $value,
        };
    }

    private function timestamp(string $value, bool $dateOnly): string
    {
        $instant = new DateTimeImmutable($value, new DateTimeZone('UTC'));

        return $dateOnly ? $instant->format('Y-m-d') : $instant->format('Y-m-d\TH:i:s.v\Z');
    }
}
