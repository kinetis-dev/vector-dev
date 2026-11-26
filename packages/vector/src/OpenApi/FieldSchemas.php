<?php

declare(strict_types=1);

namespace Kinetis\Vector\OpenApi;

use Kinetis\Validation\Constraint;

/**
 * JSON Schema 2020-12 for one compiled field: as a record carries it,
 * as a write sends it, and as a query string filters by it. Pure: a
 * field's schema depends on the compiled field alone, never on who asks,
 * which is what lets {@see OpenApiCache} keep it for the worker's life.
 */
final class FieldSchemas
{
    /** The field types a create or update body never carries. */
    private const array NOT_WRITTEN = ['id', 'hasOne', 'computed'];

    /**
     * The value a record carries for the field, or null for one no
     * record carries (a password).
     *
     * @param array<string, mixed> $field a compiled field
     * @return array<string, mixed>|null
     */
    public static function read(array $field): ?array
    {
        $nullable = ($field['nullable'] ?? true) === true;

        $schema = match ($field['type']) {
            'password' => null,
            // a record always has its identity
            'id' => self::key($field),
            'belongsTo' => self::nullable(self::related($field), $nullable),
            'hasOne' => self::nullable(self::related($field), true),
            'hasMany', 'manyToMany' => self::count(),
            // a child repeater's rows are counted like a hasMany; a JSON one's are the list itself
            'repeater' => isset($field['relation']) ? self::count() : self::nullable(self::rows($field, 'read'), $nullable),
            'tag' => self::nullable(self::tags($field), $nullable),
            'keyValue' => self::nullable(['type' => 'object', 'additionalProperties' => ['type' => 'string']], true),
            'json' => self::nullable(self::document(), $nullable),
            'booleanGroup' => self::nullable(self::flags($field, true), $nullable),
            'dateRange' => self::nullable(self::range($field), $nullable),
            'computed' => self::nullable(self::computed($field), true),
            // the editor's document, or the stored HTML where the field keeps HTML; a write always sends the document
            'richtext' => self::nullable(['type' => 'string', 'contentMediaType' => ($field['richtext']['storesHtml'] ?? false) === true ? 'text/html' : 'application/json'], $nullable),
            default => self::nullable(self::scalar($field), $nullable),
        };

        if ($schema === null) {
            return null;
        }

        if (($field['readonly'] ?? false) === true) {
            $schema['readOnly'] = true;
        }

        return $schema;
    }

    /**
     * The value a create or update body sends for the field, with the
     * keywords its rules publish, or null for a type no body writes.
     * A child repeater's rows are described by the caller, which knows
     * the child resource as this user sees it.
     *
     * @param array<string, mixed> $field a compiled field
     * @return array<string, mixed>|null
     */
    public static function write(array $field): ?array
    {
        if (in_array($field['type'], self::NOT_WRITTEN, true) || $field['type'] === 'hasMany' || isset($field['relation']) && $field['type'] === 'repeater') {
            return null;
        }

        $schema = match ($field['type']) {
            'repeater' => self::rows($field, 'write'),
            'tag' => self::tags($field),
            'belongsTo' => self::key($field),
            'manyToMany' => ['type' => 'array', 'items' => self::key($field)],
            'keyValue' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'json' => self::document(),
            'booleanGroup' => self::flags($field, false),
            'dateRange' => self::range($field),
            'password' => ['type' => 'string', 'writeOnly' => true],
            default => self::scalar($field),
        };

        return self::nullable(self::withRules($schema, $field['rules'] ?? []), ($field['nullable'] ?? true) === true && $field['type'] !== 'manyToMany');
    }

    /**
     * One filter operator's query value: typed by the field for a
     * comparison, a comma-separated list for `in`, `nin` and `between`,
     * and anything for `null` and `nnull`, which the operator alone decides.
     *
     * @param array<string, mixed> $field a compiled field
     * @return array<string, mixed>
     */
    public static function filterValue(array $field, string $operator): array
    {
        return match ($operator) {
            'in', 'nin', 'between' => ['type' => 'string', 'description' => 'Comma-separated values.'],
            'any' => ['type' => 'string', 'description' => $field['type'] === 'booleanGroup'
                ? 'Comma-separated option keys; matches a record with at least one of those flags on.'
                : 'Comma-separated ' . self::listItems($field) . '; matches a record holding at least one of them.'] + self::optionPattern($field),
            'all' => ['type' => 'string', 'description' => $field['type'] === 'booleanGroup'
                ? 'Comma-separated option keys; matches a record with every one of those flags on.'
                : 'Comma-separated ' . self::listItems($field) . '; matches a record holding every one of them.'] + self::optionPattern($field),
            'null', 'nnull' => ['type' => 'string', 'description' => 'Any value; the operator alone decides.'],
            'like', 'nlike' => ['type' => 'string'],
            default => match ($field['type']) {
                'belongsTo' => self::key($field),
                'richtext' => ['type' => 'string'],
                default => self::scalar($field),
            },
        };
    }

    /**
     * A date range's `{from, to}`, each a day or an instant.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function range(array $field): array
    {
        $end = ['type' => 'string', 'format' => ($field['range']['kind'] ?? 'date') === 'date' ? 'date' : 'date-time'];

        return [
            'type' => 'object',
            'properties' => ['from' => $end, 'to' => $end],
            'required' => ['from', 'to'],
            'additionalProperties' => false,
            'description' => 'A date range: both ends are set, `to` never before `from`; null for no range.',
        ];
    }

    /**
     * The value itself, by the field's type and compiled value type.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function scalar(array $field): array
    {
        if (in_array($field['type'], ['select', 'country'], true) && ($field['multiple'] ?? false) === true) {
            // a list of option values, each at most once
            return ['type' => 'array', 'items' => ['enum' => array_column($field['options'], 'value')], 'uniqueItems' => true];
        }

        if ($field['type'] === 'country') {
            return ['enum' => array_column($field['options'], 'value'), 'description' => 'An ISO 3166-1 alpha-2 country code.'];
        }

        if (in_array($field['type'], ['select', 'currency'], true) && is_array($field['options'] ?? null)) {
            return ['enum' => array_column($field['options'], 'value')];
        }

        return match ($field['type']) {
            'id' => self::key($field),
            'money' => ['type' => 'integer', 'description' => "Minor units of the currency."],
            'date' => ['type' => 'string', 'format' => 'date'],
            'datetime' => ['type' => 'string', 'format' => 'date-time'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'url' => ['type' => 'string', 'format' => 'uri'],
            'richtext' => ['type' => 'string', 'contentMediaType' => 'application/json'],
            default => match ($field['valueType'] ?? 'string') {
                'int' => ['type' => 'integer'],
                'float' => ['type' => 'number'],
                'bool' => ['type' => 'boolean'],
                'timestamp' => ['type' => 'string', 'format' => 'date-time'],
                default => ['type' => 'string'],
            },
        };
    }

    /** @return array<string, mixed> */
    private static function count(): array
    {
        return ['type' => 'object', 'properties' => ['count' => ['type' => 'integer', 'minimum' => 0]], 'required' => ['count']];
    }

    /**
     * What the values of a list filter are: a multiple select's options, or tags.
     *
     * @param array<string, mixed> $field
     */
    private static function listItems(array $field): string
    {
        return $field['type'] === 'tag' ? 'tags, each matched exactly as stored' : 'option values';
    }

    /**
     * Tags: a list of strings, each trimmed and at most once ignoring case.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function tags(array $field): array
    {
        $tags = is_array($field['tags'] ?? null) ? $field['tags'] : [];

        return [
            'type' => 'array',
            'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => (int) ($tags['maxLength'] ?? 50)],
            'maxItems' => (int) ($tags['maxTags'] ?? 20),
        ];
    }

    /**
     * A JSON repeater's rows: objects of its row fields and no others.
     *
     * @param array<string, mixed> $field
     * @param 'read'|'write' $direction
     * @return array<string, mixed>
     */
    private static function rows(array $field, string $direction): array
    {
        $properties = [];
        $required = [];

        foreach ($field['repeater']['rowFields'] ?? [] as $row) {
            $properties[$row['name']] = $direction === 'read' ? self::read($row) : self::write($row);

            if (($row['nullable'] ?? true) !== true) {
                $required[] = $row['name'];
            }
        }

        $item = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties, 'additionalProperties' => false];

        return [
            'type' => 'array',
            'items' => $direction === 'read' || $required === [] ? $item : [...$item, 'required' => $required],
            'minItems' => $field['repeater']['min'],
            'maxItems' => $field['repeater']['max'],
        ];
    }

    /**
     * A flag group: an object of its option keys to booleans, every key
     * present when read; a write may leave a key out, which is false.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function flags(array $field, bool $read): array
    {
        $keys = array_column($field['options'] ?? [], 'value');
        $schema = [
            'type' => 'object',
            'properties' => array_fill_keys($keys, ['type' => 'boolean']),
            'additionalProperties' => false,
        ];

        return $read ? [...$schema, 'required' => $keys] : $schema;
    }

    /**
     * A JSON column's document: an object or an array.
     *
     * @return array<string, mixed>
     */
    private static function document(): array
    {
        return ['type' => ['object', 'array']];
    }

    /**
     * The option values a list filter names, as an example of the list.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function optionPattern(array $field): array
    {
        $values = array_map(strval(...), array_column($field['options'] ?? [], 'value'));

        return $values === [] ? [] : ['examples' => [implode(',', array_slice($values, 0, 2))]];
    }

    /**
     * An identifier: the record's own for an `id` field, the target's for a relation.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function key(array $field): array
    {
        return ($field['valueType'] ?? 'int') === 'int' ? ['type' => 'integer'] : ['type' => 'string'];
    }

    /**
     * `{id, label}`, plus whatever the relation carries.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function related(array $field): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => $field['type'] === 'hasOne' ? ['type' => ['integer', 'string']] : self::key($field), 'label' => ['type' => 'string']],
            'required' => ['id', 'label'],
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function computed(array $field): array
    {
        return match ($field['computed']['as'] ?? 'text') {
            'money' => ['type' => 'integer'],
            'number' => ['type' => 'number'],
            'boolean' => ['type' => 'boolean'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'datetime' => ['type' => 'string', 'format' => 'date-time'],
            default => ['type' => 'string'],
        };
    }

    /**
     * The keywords each of the field's rules publishes itself, built
     * from the same `{constraint, args}` the writer validates against. A
     * keyword the type already states keeps the type's value.
     *
     * @param array<string, mixed> $schema
     * @param list<array{constraint: string, args: list<mixed>}> $rules
     * @return array<string, mixed>
     */
    private static function withRules(array $schema, array $rules): array
    {
        foreach ($rules as $rule) {
            $class = $rule['constraint'];

            if (!is_a($class, Constraint::class, true)) {
                continue;
            }

            foreach (new $class(...$rule['args'])->schema() as $keyword => $value) {
                $schema[$keyword] ??= $value;
            }
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function nullable(array $schema, bool $nullable): array
    {
        if (!$nullable) {
            return $schema;
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $schema['enum'] = [...$schema['enum'], null];

            return $schema;
        }

        if (is_string($schema['type'] ?? null)) {
            $schema['type'] = [$schema['type'], 'null'];
        } elseif (is_array($schema['type'] ?? null)) {
            $schema['type'] = [...$schema['type'], 'null'];
        }

        return $schema;
    }
}
