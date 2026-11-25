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
 * holds it. Lists stay lists either way.
 */
final class JsonObjects
{
    /**
     * @param array<string, array<string, mixed>> $fields field name => compiled field
     * @param array<array-key, mixed> $values field name => value, JSON objects as stdClass
     * @return array<array-key, mixed>
     */
    public static function keep(array $fields, array $values): array
    {
        foreach ($values as $name => $value) {
            $field = $fields[(string) $name] ?? null;

            if ($field === null || !ValueConverter::keepsObjects($field)) {
                $values[$name] = self::arrays($value);
            }
        }

        return $values;
    }

    /**
     * The members of the JSON object $json, read with every object kept,
     * through keep(); null when $json is not a JSON object, or holds a
     * key PHP cannot hold as a property.
     *
     * @param array<string, array<string, mixed>> $fields field name => compiled field
     * @param int<1, max> $depth
     * @return array<array-key, mixed>|null
     */
    public static function decode(array $fields, string $json, int $depth = 512): ?array
    {
        try {
            $tree = json_decode($json, false, $depth, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return $tree instanceof \stdClass ? self::keep($fields, get_object_vars($tree)) : null;
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
