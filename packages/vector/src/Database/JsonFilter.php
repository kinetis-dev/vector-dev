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
 * Each form is one method returning the same shape: a list holding
 * values (listContains), an object whose keys hold true (keysTrue).
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
     * A JSON object column whose keys hold true: any or all of $keys. Each
     * key travels inside a bound JSON object (`{"key": true}`) matched by
     * containment, so no key reaches the SQL text or a JSON path; a key
     * holding false, or absent, does not match. The caller passes only
     * keys its field declares.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param string $mode self::ANY or self::ALL
     * @param list<string> $keys at least one
     * @return array{sql: string, params: list<string>}
     */
    public static function keysTrue(string $dialect, string $column, string $mode, array $keys): array
    {
        if (!in_array($mode, [self::ANY, self::ALL], true)) {
            throw new \InvalidArgumentException("A key filter is \"any\" or \"all\", not \"{$mode}\".");
        }

        if ($keys === []) {
            throw new \InvalidArgumentException('A key filter needs at least one key.');
        }

        $quoted = Sql::quote($dialect, $column);
        $contains = $dialect === Connections::PGSQL ? "{$quoted} @> ?::jsonb" : "JSON_CONTAINS({$quoted}, ?)";
        $object = static fn (array $keys): string => self::encode((object) array_fill_keys($keys, true));

        if ($mode === self::ALL) {
            return ['sql' => $contains, 'params' => [$object($keys)]];
        }

        return [
            'sql' => '(' . implode(' OR ', array_fill(0, count($keys), $contains)) . ')',
            'params' => array_map(static fn (string $key): string => $object([$key]), $keys),
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
