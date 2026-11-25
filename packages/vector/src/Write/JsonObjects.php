<?php

declare(strict_types=1);

namespace Kinetis\Vector\Write;

/**
 * JSON objects kept where a field keeps them. A value that reaches
 * Vector with every JSON object a stdClass (a REST body read again, an
 * MCP tool's values, a stored audit entry or setting, a queued action's
 * dialog) keeps those objects for a field that keeps objects
 * (ValueConverter::keepsObjects(): a `Json` or `KeyValue` field on a
 * `stdClass` or `array|stdClass` property, and every `Json` or
 * `KeyValue` input of an action or a settings group). Every other value
 * is made plain arrays again, at any depth, as an array-decoded body
 * holds it. Lists stay lists either way. The rows of a relationship
 * repeater named in $rowFields keep objects the same way, each row
 * against the child resource's own fields.
 */
final class JsonObjects
{
    /**
     * @param array<string, array<string, mixed>> $fields field name => compiled field
     * @param array<array-key, mixed> $values field name => value, JSON objects as stdClass
     * @param array<string, array<string, array<string, mixed>>> $rowFields relationship repeater => its child's compiled fields
     * @return array<array-key, mixed>
     */
    public static function keep(array $fields, array $values, array $rowFields = []): array
    {
        foreach ($values as $name => $value) {
            $field = $fields[(string) $name] ?? null;

            if (isset($rowFields[(string) $name]) && is_array($value) && array_is_list($value)) {
                $values[$name] = array_map(static fn (mixed $row): mixed => $row instanceof \stdClass ? self::keep($rowFields[(string) $name], get_object_vars($row)) : self::arrays($row), $value);
            } elseif ($field === null || !ValueConverter::keepsObjects($field)) {
                $values[$name] = self::arrays($value);
            }
        }

        return $values;
    }

    /** $value with every stdClass in it a plain array, at any depth. */
    public static function arrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? array_map(self::arrays(...), $value) : $value;
    }
}
