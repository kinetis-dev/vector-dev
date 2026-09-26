<?php

declare(strict_types=1);

namespace Kinetis\Vector\Database;

/**
 * The few dialect differences Vector writes by hand: identifier quoting
 * inside a raw expression, a case-insensitive match, and reading a
 * boolean column, which MySQL returns as 0/1 and PostgreSQL as a boolean
 * or `t`/`f`.
 */
final class Sql
{
    /** @param 'mysql'|'pgsql' $dialect */
    public static function quote(string $dialect, string $identifier): string
    {
        return $dialect === Connections::MYSQL
            ? '`' . str_replace('`', '``', $identifier) . '`'
            : '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * `column [NOT] LIKE ?` that ignores case on both dialects: MySQL's
     * collations already do, PostgreSQL needs ILIKE.
     *
     * @param 'mysql'|'pgsql' $dialect
     */
    public static function like(string $dialect, string $column, bool $negated = false): string
    {
        $operator = $dialect === Connections::PGSQL ? 'ILIKE' : 'LIKE';

        return self::quote($dialect, $column) . ($negated ? ' NOT ' : ' ') . $operator . ' ?';
    }

    public static function bool(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => $value,
            is_int($value), is_float($value) => $value != 0,
            is_string($value) => in_array(strtolower($value), ['1', 't', 'true', 'y', 'yes', 'on'], true),
            default => false,
        };
    }
}
