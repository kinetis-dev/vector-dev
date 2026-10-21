<?php

declare(strict_types=1);

namespace Kinetis\Vector\Query;

use Kinetis\Vector\Fields\Currency;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\RichText\Document;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Serialized records as an XLSX workbook with typed cells, through
 * openspout/openspout (MIT) when the application installs it: a number
 * and a money value are numbers, a boolean a boolean, a date or a
 * date-time a real date cell (in UTC), everything else text under the
 * same formula guard as CSV. A money value is written in major units,
 * its currency in the header when the field has one currency, or taken
 * from the row when it names its currency.
 *
 * Queue worker only. openspout writes the sheet into a temporary folder
 * and zips it through ZipArchive on a real file path — blocking file I/O
 * a request worker cannot afford — so an XLSX export is always a job.
 */
final class XlsxWriter
{
    /** Whether XLSX can be written here: the package and the zip extension it needs. */
    public static function available(): bool
    {
        return class_exists(Writer::class) && extension_loaded('zip');
    }

    /**
     * @param list<string> $fields
     * @param iterable<array<string, mixed>> $records
     */
    public static function document(ResourceDescriptor $resource, array $fields, iterable $records): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vector-xlsx-');

        if ($path === false) {
            throw new ExportWriteException('Could not create a temporary file for the XLSX export.');
        }

        try {
            $writer = new Writer();
            $writer->openToFile($path);
            $columns = array_map(static fn (string $name): array => $resource->field($name) ?? ['name' => $name, 'type' => 'string', 'label' => $name, 'props' => []], $fields);
            $writer->addRow(new Row(array_map(static fn (array $field): Cell => new Cell\StringCell(self::header($field)), $columns)));
            $document = new Document();
            $date = new Style()->withFormat('yyyy-mm-dd');
            $dateTime = new Style()->withFormat('yyyy-mm-dd hh:mm:ss');

            foreach ($records as $record) {
                $cells = [];

                foreach ($columns as $field) {
                    $cells[] = self::cell($field, $record, $document, $date, $dateTime);
                }

                $writer->addRow(new Row($cells));
            }

            $writer->close();
            $contents = file_get_contents($path);

            return $contents === false ? throw new ExportWriteException('Could not read the XLSX export back.') : $contents;
        } finally {
            @unlink($path);
        }
    }

    /** @param array<string, mixed> $field */
    private static function header(array $field): string
    {
        $label = (string) ($field['label'] ?? $field['name']);
        $currency = $field['props']['currency'] ?? null;

        return match (true) {
            $field['type'] === 'money' && is_string($currency) => "{$label} ({$currency})",
            $field['type'] === 'datetime' => "{$label} (UTC)",
            default => $label,
        };
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $record
     */
    private static function cell(array $field, array $record, Document $document, Style $date, Style $dateTime): Cell
    {
        $value = $record[$field['name']] ?? null;

        if ($value === null || $value === '') {
            return new Cell\EmptyCell(null);
        }

        return match (true) {
            $field['type'] === 'boolean' || is_bool($value) => new Cell\BooleanCell((bool) $value),
            $field['type'] === 'money' && is_numeric($value) => self::money($field, $record, $value),
            ($field['type'] === 'number' || ($field['computed']['as'] ?? null) === 'number') && is_numeric($value) => new Cell\NumericCell(is_int($value) ? $value : (float) $value),
            $field['type'] === 'date' && is_string($value) && ($day = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), new \DateTimeZone('UTC'))) !== false
                => new Cell\DateTimeCell($day, $date),
            $field['type'] === 'datetime' && is_string($value) && ($moment = self::moment($value)) !== null => new Cell\DateTimeCell($moment, $dateTime),
            $field['type'] === 'richtext' && is_string($value) => self::text($document->toPlainText($value)),
            default => self::text(self::plain($value)),
        };
    }

    /**
     * Minor units as major ones, in the field's currency or the row's; a
     * value whose currency is unknown here stays in minor units.
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $record
     */
    private static function money(array $field, array $record, int|float|string $value): Cell
    {
        $currency = $field['props']['currency'] ?? null;
        $path = $field['props']['currencyField'] ?? null;

        if (!is_string($currency) && is_string($path)) {
            [$first, $rest] = array_pad(explode('.', $path, 2), 2, null);
            $carrier = $record[(string) $first] ?? null;
            $currency = match (true) {
                $rest === null => $carrier,
                is_array($carrier) => $carrier[$rest] ?? null,
                default => null,
            };
        }

        if (!is_string($currency) || $currency === '') {
            return new Cell\NumericCell((int) $value);
        }

        $digits = Currency::minorUnits($currency);
        $format = $digits === 0 ? '#,##0' : '#,##0.' . str_repeat('0', $digits);

        return new Cell\NumericCell(round((float) $value / 10 ** $digits, $digits), new Style()->withFormat($format));
    }

    private static function moment(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value)->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private static function plain(mixed $value): string
    {
        return match (true) {
            is_array($value) && array_key_exists('label', $value) => (string) $value['label'],
            is_array($value) && array_key_exists('count', $value) => (string) $value['count'],
            is_array($value), $value instanceof \stdClass => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /** A text cell, never a formula: openspout would read a leading `=` as one. */
    private static function text(string $text): Cell
    {
        return $text === '' ? new Cell\EmptyCell(null) : new Cell\StringCell(CsvWriter::guarded($text));
    }
}
