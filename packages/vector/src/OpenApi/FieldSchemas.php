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
            'hasMany', 'manyToMany' => ['type' => 'object', 'properties' => ['count' => ['type' => 'integer', 'minimum' => 0]], 'required' => ['count']],
            'keyValue' => self::nullable(['type' => 'object', 'additionalProperties' => ['type' => 'string']], true),
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
     * A repeater's rows are described by the caller, which knows the
     * child resource as this user sees it.
     *
     * @param array<string, mixed> $field a compiled field
     * @return array<string, mixed>|null
     */
    public static function write(array $field): ?array
    {
        if (in_array($field['type'], self::NOT_WRITTEN, true) || $field['type'] === 'hasMany') {
            return null;
        }

        $schema = match ($field['type']) {
            'belongsTo' => self::key($field),
            'manyToMany' => ['type' => 'array', 'items' => self::key($field)],
            'keyValue' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
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
     * The value itself, by the field's type and compiled value type.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function scalar(array $field): array
    {
        if (in_array($field['type'], ['enum', 'currency'], true) && is_array($field['options'] ?? null)) {
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
