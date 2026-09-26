<?php

declare(strict_types=1);

namespace Kinetis\Vector\Query;

use Kinetis\Vector\Registry\ResourceDescriptor;

/**
 * Serialized records as RFC 4180 CSV with a UTF-8 byte-order mark, one
 * column per field: a relation as its label, a collection as its
 * count, a boolean as true/false. A value starting with a formula
 * trigger is prefixed with an apostrophe so a spreadsheet shows it as
 * text instead of evaluating it.
 */
final class CsvWriter
{
    /**
     * @param list<string> $fields
     * @param iterable<array<string, mixed>> $records
     */
    public static function document(ResourceDescriptor $resource, array $fields, iterable $records): string
    {
        $out = fopen('php://memory', 'r+');

        if ($out === false) {
            throw new \RuntimeException('Could not open a memory stream.');
        }

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(static fn (string $name): string => (string) ($resource->field($name)['label'] ?? $name), $fields), ',', '"', '\\', "\r\n");

        foreach ($records as $record) {
            fputcsv($out, array_map(static fn (string $name): string => self::cell($record[$name] ?? null), $fields), ',', '"', '\\', "\r\n");
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv === false ? '' : $csv;
    }

    private static function cell(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) && array_key_exists('label', $value) => (string) $value['label'],
            is_array($value) && array_key_exists('count', $value) => (string) $value['count'],
            is_array($value), $value instanceof \stdClass => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            is_scalar($value) => (string) $value,
            default => '',
        };

        return $text !== '' && str_contains('=+-@\t\r', $text[0]) ? "'" . $text : $text;
    }
}
