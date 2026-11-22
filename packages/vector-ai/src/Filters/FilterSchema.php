<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Filters;

/**
 * The JSON schema a filter answer is asked in, built per request from the
 * fields the model may filter by:
 *
 *     { conditions: [Condition], anyOf: [[[Condition]]], q: string|null, explanation: string }
 *
 * where a Condition is `{ field, op, value }`, `field` one of those
 * fields, `op` one of the operators any of them admits, and `value` a
 * string, a list of strings (two for `between`, any for `in`/`nin`) or
 * `{ lookup }`; a relation's record named in words. `anyOf` holds groups,
 * each a list of alternatives, each the conditions that must hold
 * together; groups are one level deep.
 *
 * The operators are the union of the fields' own, not paired per field:
 * a per-field `anyOf` of `{field: const, op: enum}` is more than every
 * provider's structured mode takes. The schema is a guide to the model;
 * the list's own validator is the gate (FilterTranslator).
 */
final class FilterSchema
{
    /**
     * @param list<array<string, mixed>> $fields the filterable fields of ModelBoundary::schema()
     * @return array<string, mixed>
     */
    public static function for(array $fields): array
    {
        $names = array_map(static fn (array $f): string => (string) $f['name'], $fields);
        $operators = [];

        foreach ($fields as $field) {
            foreach ($field['operators'] ?? [] as $op) {
                $operators[(string) $op] = true;
            }
        }

        $condition = [
            'type' => 'object',
            'properties' => [
                'field' => ['type' => 'string', 'enum' => $names],
                'op' => ['type' => 'string', 'enum' => array_keys($operators)],
                'value' => [
                    'description' => 'A string; a list of strings for in, nin and between (two: from, to); {"lookup": words} to name a related record; "" for null and nnull.',
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'array', 'items' => ['type' => 'string']],
                        ['type' => 'object', 'properties' => ['lookup' => ['type' => 'string']], 'required' => ['lookup'], 'additionalProperties' => false],
                    ],
                ],
            ],
            'required' => ['field', 'op', 'value'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'conditions' => ['type' => 'array', 'description' => 'Conditions that must all hold.', 'items' => $condition],
                'anyOf' => [
                    'type' => 'array',
                    'description' => 'Groups, each a list of alternatives, one of which must hold; an alternative is a list of conditions that hold together.',
                    'items' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => $condition]],
                ],
                'q' => ['type' => ['string', 'null'], 'description' => 'Free-text search, only for words no field condition expresses; else null.'],
                'explanation' => ['type' => 'string', 'description' => 'One sentence saying what the filter keeps, with absolute dates.'],
            ],
            'required' => ['conditions', 'anyOf', 'q', 'explanation'],
            'additionalProperties' => false,
        ];
    }
}
