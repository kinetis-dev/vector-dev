<?php

declare(strict_types=1);

namespace Kinetis\Vector\Database;

/**
 * The SQL of a filter on a JSON column, for `whereRaw()`: a fragment and
 * its positional parameters. The ORM refuses predicates on a JSON
 * property, so Vector writes these by hand, per dialect (MySQL and
 * MariaDB alike, or PostgreSQL `jsonb`).
 *
 * Values are always bound, never written into the SQL. The column comes
 * only from a compiled descriptor and is quoted here. A PostgreSQL `?`
 * operator is written `??`, the query builder's escape for a literal
 * question mark.
 *
 * Each form is one method returning the same shape, so another form
 * (a key holding true, a list of strings) joins as a method of its own.
 */
final class JsonFilter
{
    /** At least one of the values is in the list. */
    public const string ANY = 'any';

    /** Every one of the values is in the list. */
    public const string ALL = 'all';

    /**
     * A JSON array column that holds any or all of $values, each matched
     * with its JSON type: the string "1" is not the number 1.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param string $mode self::ANY or self::ALL
     * @param list<int|string> $values at least one
     * @return array{sql: string, params: list<int|string>}
     */
    public static function listContains(string $dialect, string $column, string $mode, array $values): array
    {
        if (!in_array($mode, [self::ANY, self::ALL], true)) {
            throw new \InvalidArgumentException("A list filter is \"any\" or \"all\", not \"{$mode}\".");
        }

        if ($values === []) {
            throw new \InvalidArgumentException('A list filter needs at least one value.');
        }

        $quoted = Sql::quote($dialect, $column);

        if ($mode === self::ALL) {
            // containment of the whole list: every value present, in any order
            return $dialect === Connections::PGSQL
                ? ['sql' => "{$quoted} @> ?::jsonb", 'params' => [self::encode($values)]]
                : ['sql' => "JSON_CONTAINS({$quoted}, ?)", 'params' => [self::encode($values)]];
        }

        if ($dialect === Connections::PGSQL) {
            return self::pgsqlAny($quoted, $values);
        }

        // one JSON_CONTAINS per value rather than JSON_OVERLAPS, which MariaDB lacks
        return [
            'sql' => '(' . implode(' OR ', array_fill(0, count($values), "JSON_CONTAINS({$quoted}, ?)")) . ')',
            'params' => array_map(self::encode(...), $values),
        ];
    }

    /**
     * `?|` matches top-level string elements, so strings take it with a
     * text array of bound values; a number is matched by containment.
     *
     * @param non-empty-list<int|string> $values
     * @return array{sql: string, params: list<int|string>}
     */
    private static function pgsqlAny(string $quoted, array $values): array
    {
        $strings = array_values(array_filter($values, is_string(...)));
        $numbers = array_values(array_filter($values, is_int(...)));
        $parts = [];
        $params = [];

        if ($strings !== []) {
            $parts[] = "{$quoted} ??| ARRAY[" . implode(', ', array_fill(0, count($strings), '?')) . ']::text[]';
            $params = $strings;
        }

        foreach ($numbers as $number) {
            $parts[] = "{$quoted} @> ?::jsonb";
            $params[] = self::encode([$number]);
        }

        return ['sql' => '(' . implode(' OR ', $parts) . ')', 'params' => $params];
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
