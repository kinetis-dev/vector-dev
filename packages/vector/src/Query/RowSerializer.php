<?php

declare(strict_types=1);

namespace Kinetis\Vector\Query;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Vector\Exception\StoredValueException;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\VectorResource;

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
     * @param VectorResource|null $instance the resource, which computes its computed fields
     * @return array<string, mixed>
     */
    public function serialize(ResourceDescriptor $resource, array $row, array $fieldNames, array $relations, ?VectorResource $instance = null): array
    {
        $record = [];

        foreach ($fieldNames as $name) {
            $field = $resource->field($name);

            if ($field === null) {
                continue;
            }

            // computed once every other field is serialized, from the record itself
            if ($field['type'] === 'computed') {
                $record[$name] = null;

                continue;
            }

            $record[$name] = match ($field['type']) {
                'belongsTo' => $this->belongsTo($field, $row, $relations[$name] ?? []),
                'hasOne' => $relations[$name][$this->key($row, $resource)] ?? null,
                'hasMany', 'manyToMany' => ['count' => (int) ($relations[$name][$this->key($row, $resource)] ?? 0)],
                // a child repeater's rows are child records, counted; a JSON one's are the column's list
                'repeater' => isset($field['relation'])
                    ? ['count' => (int) ($relations[$name][$this->key($row, $resource)] ?? 0)]
                    : self::list($name, $row[$field['column']] ?? null),
                'tag' => self::list($name, $row[$field['column']] ?? null),
                'keyValue' => self::keyValue($name, $row[$field['column']] ?? null),
                'json' => self::json($name, $row[$field['column']] ?? null),
                'select', 'country' => self::select($name, $field, $row[$field['column']] ?? null),
                'booleanGroup' => self::flags($name, $field, $row),
                default => $this->scalar($field, $row[$field['column']] ?? null),
            };
        }

        foreach ($resource->computedMethods($fieldNames) as $name => $method) {
            $record[$name] = $instance === null ? null : self::computed($resource->field($name) ?? [], $instance->{$method}($record));
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
     * The stored JSON as an object of text values; an object even when
     * empty or keyed by digits; a nested value reads as its JSON text.
     */
    private static function keyValue(string $name, mixed $stored): ?\stdClass
    {
        $decoded = self::decode($name, $stored, true);

        if ($decoded === null) {
            return null;
        }

        if (!is_array($decoded)) {
            throw StoredValueException::invalidJson($name);
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

    /**
     * A JSON column's document as it is stored: an object stays an
     * object, even an empty one, and a list stays a list.
     */
    private static function json(string $name, mixed $stored): mixed
    {
        return self::decode($name, $stored, false);
    }

    /**
     * A JSON column holding a list (tags, a JSON repeater's rows), decoded
     * to arrays; anything but a list is not what the field stored.
     *
     * @return list<mixed>|null
     */
    private static function list(string $name, mixed $stored): ?array
    {
        $decoded = self::decode($name, $stored, true);

        if ($decoded === null) {
            return null;
        }

        return is_array($decoded) && array_is_list($decoded) ? $decoded : throw StoredValueException::invalidJson($name);
    }

    /**
     * A select's stored value, checked against its options: one value, or
     * the JSON list a multiple one holds. A value that is no option throws
     * rather than vanishing from the record.
     *
     * @param array<string, mixed> $field
     * @return int|string|list<int|string>|null
     */
    private static function select(string $name, array $field, mixed $stored): int|string|array|null
    {
        if ($stored === null) {
            return null;
        }

        $known = [];

        foreach ($field['options'] as $option) {
            $known[(string) $option['value']] = $option['value'];
        }

        $find = static function (mixed $value) use ($known, $name): int|string {
            $text = is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);

            return array_key_exists($text, $known) && is_scalar($value) ? $known[$text] : throw StoredValueException::unknownOption($name, $text);
        };

        if (($field['multiple'] ?? false) !== true) {
            return $find($stored);
        }

        $list = self::decode($name, $stored, true);

        if (!is_array($list) || !array_is_list($list)) {
            throw StoredValueException::invalidJson($name);
        }

        return array_map($find, $list);
    }

    /**
     * A flag group's object of every option key to true or false: read
     * from each flag's column, or from the JSON object, where a key left
     * out is false and a key that is no option throws.
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $row
     * @return array<string, bool>|null
     */
    private static function flags(string $name, array $field, array $row): ?array
    {
        $flags = array_fill_keys(array_column($field['options'], 'value'), false);

        if (isset($field['flags'])) {
            foreach ($field['flags'] as $key => $column) {
                $flags[$key] = \Kinetis\Vector\Database\Sql::bool($row[$column] ?? false);
            }

            return $flags;
        }

        $stored = self::decode($name, $row[$field['column']] ?? null, true);

        if ($stored === null) {
            return null;
        }

        if (!is_array($stored) || ($stored !== [] && array_is_list($stored))) {
            throw StoredValueException::invalidJson($name);
        }

        foreach ($stored as $key => $on) {
            $key = (string) $key;

            if (!array_key_exists($key, $flags)) {
                throw StoredValueException::unknownOption($name, $key);
            }

            $flags[$key] = is_bool($on) ? $on : throw StoredValueException::invalidJson($name);
        }

        return $flags;
    }

    /** A JSON column as the driver returns it: text to decode, or a value it already decoded. */
    private static function decode(string $name, mixed $stored, bool $associative): mixed
    {
        if ($stored === null) {
            return null;
        }

        if (!is_string($stored)) {
            return $associative || !is_array($stored) ? $stored : json_decode(json_encode($stored, JSON_THROW_ON_ERROR), false);
        }

        try {
            return json_decode($stored, $associative, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw StoredValueException::invalidJson($name);
        }
    }

    /**
     * A computed value, typed by what it is shown as.
     *
     * @param array<string, mixed> $field
     */
    private static function computed(array $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field['computed']['as'] ?? 'text') {
            'money' => (int) round(is_numeric($value) ? (float) $value : 0.0),
            'number' => match (true) {
                is_int($value) => $value,
                is_numeric($value) => (float) $value,
                default => null,
            },
            'boolean' => (bool) $value,
            'date' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : self::scalarText($value),
            'datetime' => $value instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z')
                : self::scalarText($value),
            default => is_scalar($value) || $value instanceof \Stringable ? (string) $value : null,
        };
    }

    private static function scalarText(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
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
