<?php

declare(strict_types=1);

namespace Kinetis\Vector\Write;

/**
 * Evaluates a field's compiled conditions (`visibleWhen`, `requiredWhen`,
 * `disabledWhen`) against the values of the form it belongs to, the same
 * way the UI does: a relation compares by its id, a boolean as
 * true/false, everything else by its string form.
 *
 * @phpstan-type Condition array{field: string, op: string, value: mixed}
 * @phpstan-type FieldState array{visible: bool, required: bool, disabled: bool}
 */
final class Conditions
{
    /**
     * Whether every condition holds; an empty list holds.
     *
     * @param list<Condition> $conditions
     * @param array<string, mixed> $values field name => value, raw or serialized
     */
    public static function hold(array $conditions, array $values): bool
    {
        foreach ($conditions as $condition) {
            $actual = self::normalize($values[$condition['field']] ?? null);
            $expected = $condition['value'];
            $holds = match ($condition['op']) {
                'eq' => $actual === self::normalize($expected),
                'ne' => $actual !== self::normalize($expected),
                'in' => in_array($actual, array_map(self::normalize(...), is_array($expected) ? $expected : [$expected]), true),
                'nin' => !in_array($actual, array_map(self::normalize(...), is_array($expected) ? $expected : [$expected]), true),
                'null' => $actual === null,
                'nnull' => $actual !== null,
                default => false,
            };

            if (!$holds) {
                return false;
            }
        }

        return true;
    }

    /**
     * What the form decides for each conditioned field under $values:
     * whether it is shown, required and editable.
     *
     * @param array<string, array<string, mixed>> $fields field name => compiled field
     * @param array<string, mixed> $values
     * @return array<string, FieldState> only the fields that have conditions
     */
    public static function states(array $fields, array $values): array
    {
        $states = [];

        foreach ($fields as $name => $field) {
            /** @var array{visible: list<Condition>, required: list<Condition>, disabled: list<Condition>}|null $conditions */
            $conditions = $field['conditions'] ?? null;

            if ($conditions === null || ($conditions['visible'] === [] && $conditions['required'] === [] && $conditions['disabled'] === [])) {
                continue;
            }

            $states[$name] = [
                'visible' => self::hold($conditions['visible'], $values),
                'required' => $conditions['required'] !== [] && self::hold($conditions['required'], $values),
                'disabled' => $conditions['disabled'] !== [] && self::hold($conditions['disabled'], $values),
            ];
        }

        return $states;
    }

    /** An empty value for a required check: null, an empty string, an empty list. */
    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private static function normalize(mixed $value): ?string
    {
        if (is_array($value) && array_key_exists('id', $value)) {
            $value = $value['id'];
        }

        return match (true) {
            $value === null, $value === '' => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            $value instanceof \BackedEnum => (string) $value->value,
            default => null,
        };
    }
}
