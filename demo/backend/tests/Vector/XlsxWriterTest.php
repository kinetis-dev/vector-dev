<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Vector\Query\XlsxWriter;
use Kinetis\Vector\Registry\ResourceDescriptor;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\TestCase;

/**
 * The demo installs openspout/openspout and the zip extension, so XLSX can
 * be written. The workbook is read back through openspout's own reader for
 * values and types, and through its XML for the number format of each
 * cell, which the reader does not return.
 */
final class XlsxWriterTest extends TestCase
{
    private const string RICH_TEXT = '{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Hello"}]}]}';

    public function testXlsxIsAvailableWithThePackageAndTheZipExtension(): void
    {
        self::assertTrue(class_exists(\OpenSpout\Writer\XLSX\Writer::class));
        self::assertTrue(extension_loaded('zip'));
        self::assertTrue(XlsxWriter::available());
    }

    public function testTheHeaderIsTheLabelWithTheCurrencyOrTheTimeZone(): void
    {
        $resource = new ResourceDescriptor(['fields' => [
            self::field('title', 'string', 'Title'),
            self::field('year', 'number', 2026),
            self::field('price', 'money', 'Price', ['currency' => 'EUR']),
            self::field('amount', 'money', 'Amount', ['currencyField' => 'currency']),
            self::field('tag', 'string', 'Tag', ['currency' => 'EUR']),
            self::field('seen', 'datetime', 'Seen'),
            ['name' => 'plain', 'type' => 'string', 'props' => []],
        ]]);

        [$rows] = self::write($resource, ['title', 'year', 'price', 'amount', 'tag', 'seen', 'plain', 'extra'], [['extra' => 'hello']]);

        self::assertSame(['Title', '2026', 'Price (EUR)', 'Amount', 'Tag', 'Seen (UTC)', 'plain', 'extra'], $rows[0]);
        // a field the resource does not know is a text column under its own name
        self::assertSame('hello', $rows[1][7]);
    }

    public function testEachTypeIsItsOwnKindOfCell(): void
    {
        $resource = new ResourceDescriptor(['fields' => [
            self::field('flag', 'boolean'),
            self::field('note', 'string'),
            self::field('count', 'number'),
            ['name' => 'score', 'type' => 'string', 'label' => 'score', 'props' => [], 'computed' => ['as' => 'number']],
            self::field('day', 'date'),
            self::field('at', 'datetime'),
            self::field('body', 'richtext'),
            self::field('raw', 'string'),
            self::field('code', 'string'),
        ]]);
        $names = ['flag', 'note', 'count', 'score', 'day', 'at', 'body', 'raw', 'code'];

        [$rows, $formats] = self::write($resource, $names, [
            ['flag' => 1, 'note' => true, 'count' => 42, 'score' => '3.25', 'day' => '2026-01-02T10:30:00Z', 'at' => '2026-01-02T10:30:00+02:00', 'body' => self::RICH_TEXT, 'raw' => self::RICH_TEXT, 'code' => '2026-01-02'],
            ['flag' => 0, 'note' => false, 'count' => '12.5', 'score' => 7, 'day' => '2026-03-04', 'at' => 'later', 'body' => ['a' => 1], 'raw' => '=1+1', 'code' => 1.5],
            ['flag' => true, 'note' => 7, 'count' => 'abc', 'score' => 'n/a', 'day' => 'soon', 'at' => 5, 'body' => 'Plain words', 'raw' => null, 'code' => ''],
            ['flag' => null, 'note' => null, 'count' => null, 'score' => null, 'day' => 20260102, 'at' => null, 'body' => null, 'raw' => null, 'code' => 'end'],
        ]);

        self::assertSame([true, true, 42, 3.25], array_slice($rows[1], 0, 4));
        self::assertSame('2026-01-02 00:00:00', self::moment($rows[1][4]));
        self::assertSame('2026-01-02 08:30:00', self::moment($rows[1][5]));
        self::assertSame(['Hello', self::RICH_TEXT, '2026-01-02'], array_slice($rows[1], 6));
        self::assertSame(['yyyy-mm-dd', 'yyyy-mm-dd hh:mm:ss'], [$formats['E2'], $formats['F2']]);

        self::assertSame([false, false, 12.5, 7], array_slice($rows[2], 0, 4));
        self::assertSame('2026-03-04 00:00:00', self::moment($rows[2][4]));
        self::assertSame(['later', '{"a":1}', "'=1+1", '1.5'], array_slice($rows[2], 5));

        self::assertSame([true, '7', 'abc', 'n/a', 'soon', '5', 'Plain words'], array_slice($rows[3], 0, 7));
        self::assertSame(['', '', '', '', '20260102', '', '', '', 'end'], $rows[4]);
    }

    public function testMoneyIsInMajorUnitsOfItsCurrencyWithThatCurrencysDigits(): void
    {
        $resource = new ResourceDescriptor(['fields' => [
            self::field('eur', 'money', 'EUR', ['currency' => 'EUR']),
            self::field('jpy', 'money', 'JPY', ['currency' => 'JPY']),
            self::field('kwd', 'money', 'KWD', ['currency' => 'KWD']),
            self::field('own', 'money', 'Own', ['currencyField' => 'currency']),
            self::field('nested', 'money', 'Nested', ['currencyField' => 'account.code']),
            self::field('dotted', 'money', 'Dotted', ['currencyField' => 'meta.cur.code']),
            self::field('fixed', 'money', 'Fixed', ['currency' => 'JPY', 'currencyField' => 'currency']),
            self::field('bare', 'money'),
        ]]);
        $names = ['eur', 'jpy', 'kwd', 'own', 'nested', 'dotted', 'fixed', 'bare'];

        [$rows, $formats] = self::write($resource, $names, [
            [
                'eur' => 12345, 'jpy' => 1234, 'kwd' => 12345, 'own' => 999, 'nested' => 1500, 'dotted' => 700, 'fixed' => 250, 'bare' => '1999.7',
                'currency' => 'KWD', 'account' => ['code' => 'JPY'], 'meta' => ['cur.code' => 'JPY', 'cur' => 'KWD'],
            ],
            // a currency the row does not hold, or holds as no code, leaves the value in minor units
            ['eur' => '12345.6', 'jpy' => 'n/a', 'kwd' => 1, 'own' => '1999', 'nested' => 1500, 'dotted' => 5, 'currency' => '', 'account' => 'KWD', 'meta' => ['cur' => 'KWD']],
            ['own' => 42, 'nested' => '1500.9', 'currency' => 5, 'account' => ['code' => null]],
        ]);

        self::assertSame([123.45, 1234, 12.345, 0.999, 1500, 700, 250, 1999], $rows[1]);
        self::assertSame(
            ['#,##0.00', '#,##0', '#,##0.000', '#,##0.000', '#,##0', '#,##0', '#,##0', 'General'],
            array_map(static fn (string $column): string => $formats["{$column}2"], ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H']),
        );

        self::assertSame([123.46, 'n/a', 0.001, 1999, 1500, 5, '', ''], $rows[2]);
        self::assertSame(['General', 'General', 'General'], [$formats['D3'], $formats['E3'], $formats['F3']]);

        self::assertSame(['', '', '', 42, 1500, '', '', ''], $rows[3]);
    }

    public function testAValueThatIsNoScalarIsItsLabelItsCountOrItsJson(): void
    {
        $resource = new ResourceDescriptor(['fields' => [self::field('value', 'string'), self::field('row', 'number')]]);
        $values = [
            ['label' => 'Alice', 'count' => 2, 'id' => 3],
            ['label' => 7],
            ['count' => 3],
            ['é' => 'ü'],
            (object) ['a' => 'ö'],
            ['label' => ''],
            new \ArrayObject(['label' => 'x']),
            '=SUM(A1)',
        ];

        // the row number keeps a row whose value is empty from being skipped on the way back
        [$rows] = self::write($resource, ['value', 'row'], array_map(static fn (mixed $value, int $row): array => ['value' => $value, 'row' => $row], $values, array_keys($values)));

        self::assertSame(['Alice', '7', '3', '{"é":"ü"}', '{"a":"ö"}', '', '', "'=SUM(A1)"], array_column(array_slice($rows, 1), 0));
        self::assertSame(array_keys($values), array_column(array_slice($rows, 1), 1));
    }

    public function testTheTemporaryFileIsRemovedAfterAWriteAndAfterAFailure(): void
    {
        $resource = new ResourceDescriptor(['fields' => [self::field('title', 'string')]]);
        /** @var \ArrayObject<int, string> $held the file each write held */
        $held = new \ArrayObject();
        // openspout holds the file it writes open until it closes it: this process's descriptors name exactly this write's file
        $records = static function (bool $fail) use ($held): \Generator {
            $open = array_values(array_filter(
                array_map(static fn (string $fd): string => (string) @readlink($fd), glob('/proc/self/fd/*') ?: []),
                static fn (string $target): bool => str_starts_with(basename($target), 'vector-xlsx-'),
            ));
            self::assertCount(1, $open);
            $held->append($open[0]);

            yield ['title' => 'one'];

            if ($fail) {
                throw new \RuntimeException('The records ran out.');
            }
        };

        if (!is_dir('/proc/self/fd')) {
            self::markTestSkipped('Needs /proc to see which temporary file the writer holds.');
        }

        self::assertNotSame('', XlsxWriter::document($resource, ['title'], $records(false)));
        $files = $held->getArrayCopy();
        self::assertCount(1, $files);
        self::assertFileDoesNotExist($files[0]);

        try {
            XlsxWriter::document($resource, ['title'], $records(true));
            self::fail('The failure did not reach the caller.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The records ran out.', $failure->getMessage());
        }

        $files = $held->getArrayCopy();
        self::assertCount(2, $files);
        self::assertFileDoesNotExist($files[1]);
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private static function field(string $name, string $type, string|int|null $label = null, array $props = []): array
    {
        return ['name' => $name, 'type' => $type, 'label' => $label ?? $name, 'props' => $props];
    }

    private static function moment(mixed $cell): string
    {
        self::assertInstanceOf(\DateTimeImmutable::class, $cell);

        return $cell->format('Y-m-d H:i:s');
    }

    /**
     * The rows as openspout reads them back, and each written cell's number
     * format by its reference ("B2").
     *
     * @param list<string> $fields
     * @param list<array<string, mixed>> $records
     * @return array{list<list<mixed>>, array<string, string>}
     */
    private static function write(ResourceDescriptor $resource, array $fields, array $records): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        self::assertIsString($path);
        file_put_contents($path, XlsxWriter::document($resource, $fields, $records));

        try {
            $reader = new Reader();
            $reader->open($path);
            $rows = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_pad(array_values($row->toArray()), count($fields), '');
                }
            }

            $reader->close();

            return [$rows, self::formats($path)];
        } finally {
            unlink($path);
        }
    }

    /** @return array<string, string> cell reference => number format code */
    private static function formats(string $path): array
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $styles = new \DOMDocument();
        $styles->loadXML((string) $zip->getFromName('xl/styles.xml'));
        $sheet = new \DOMDocument();
        $sheet->loadXML((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();

        // the built-in codes openspout writes by number (StyleRegistry), the others declared in numFmts
        $codes = [0 => 'General', 3 => '#,##0', 4 => '#,##0.00'];

        foreach ($styles->getElementsByTagName('numFmt') as $format) {
            $codes[(int) $format->getAttribute('numFmtId')] = $format->getAttribute('formatCode');
        }

        $styleFormats = [];
        $cellXfs = $styles->getElementsByTagName('cellXfs')->item(0);
        self::assertNotNull($cellXfs);

        foreach ($cellXfs->getElementsByTagName('xf') as $xf) {
            $styleFormats[] = $codes[(int) $xf->getAttribute('numFmtId')];
        }

        $formats = [];

        foreach ($sheet->getElementsByTagName('c') as $cell) {
            $formats[$cell->getAttribute('r')] = $styleFormats[(int) $cell->getAttribute('s')];
        }

        return $formats;
    }
}
