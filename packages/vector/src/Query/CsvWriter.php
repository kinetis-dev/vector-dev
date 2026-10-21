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
     * @param int|null $maxBytes the most the document may grow to; past it the rows stop and a LengthException says why
     * @throws \LengthException
     */
    public static function document(ResourceDescriptor $resource, array $fields, iterable $records, ?int $maxBytes = null): string
    {
        $out = fopen('php://memory', 'r+');

        if ($out === false) {
            throw new ExportWriteException('Could not open a memory stream.');
        }

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(static fn (string $name): string => (string) ($resource->field($name)['label'] ?? $name), $fields), ',', '"', '\\', "\r\n");

        // a rich-text value exports as its plain text, never its JSON or HTML
        $document = new \Kinetis\Vector\RichText\Document();
        $richText = array_filter($fields, static fn (string $name): bool => ($resource->field($name)['type'] ?? null) === 'richtext');

        foreach ($records as $record) {
            fputcsv($out, array_map(static fn (string $name): string => self::cell(
                in_array($name, $richText, true) && is_string($record[$name] ?? null) ? $document->toPlainText($record[$name]) : ($record[$name] ?? null),
            ), $fields), ',', '"', '\\', "\r\n");

            if ($maxBytes !== null && ftell($out) > $maxBytes) {
                fclose($out);

                throw new \LengthException(sprintf('The export is larger than %d MiB, the most a job file holds. Narrow the selection or the columns.', intdiv($maxBytes, 1048576)));
            }
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

        return self::guarded($text);
    }

    /**
     * Text a spreadsheet shows as text: one starting with a formula
     * trigger gets an apostrophe in front. A signed number is not a
     * formula: `-5` must import back as -5.
     */
    public static function guarded(string $text): string
    {
        $signedNumber = ($text[0] ?? '') !== '' && str_contains('+-', $text[0]) && is_numeric($text);

        return $text !== '' && str_contains("=+-@\t\r", $text[0]) && !$signedNumber ? "'" . $text : $text;
    }
}
