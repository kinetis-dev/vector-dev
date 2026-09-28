<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Vector\Query\CsvWriter;
use Kinetis\Vector\Registry\ResourceDescriptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The formula-injection guard CSV and XLSX exports share: text starting
 * with a spreadsheet formula trigger gets an apostrophe in front, a
 * signed number does not. No database: the guard and the document are
 * pure functions of their input.
 */
final class CsvFormulaGuardTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function cells(): iterable
    {
        yield 'equals' => ['=SUM(A1:A2)', "'=SUM(A1:A2)"];
        yield 'plus' => ['+cmd', "'+cmd"];
        yield 'minus' => ['-cmd', "'-cmd"];
        yield 'at' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'tab' => ["\t=1+1", "'\t=1+1"];
        yield 'carriage return' => ["\r=1+1", "'\r=1+1"];
        yield 'ordinary leading t' => ['total', 'total'];
        yield 'ordinary leading r' => ['report', 'report'];
        yield 'negative number' => ['-5', '-5'];
        yield 'positive number' => ['+3.25', '+3.25'];
        yield 'negative exponent' => ['-1e3', '-1e3'];
        yield 'empty' => ['', ''];
        yield 'boolean text' => ['true', 'true'];
    }

    #[DataProvider('cells')]
    public function testTheGuardPrefixesExactlyTheFormulaTriggers(string $text, string $guarded): void
    {
        self::assertSame($guarded, CsvWriter::guarded($text));
    }

    public function testTheDocumentGuardsEveryCell(): void
    {
        $resource = new ResourceDescriptor(['fields' => [
            ['name' => 'text', 'label' => 'Text', 'type' => 'text'],
            ['name' => 'flag', 'label' => 'Flag', 'type' => 'boolean'],
        ]]);

        $csv = CsvWriter::document($resource, ['text', 'flag'], [
            ['text' => '=1+1', 'flag' => true],
            ['text' => "\tx", 'flag' => false],
            ['text' => "\rx", 'flag' => null],
            ['text' => 'total', 'flag' => true],
            ['text' => 'report', 'flag' => false],
            ['text' => '-5', 'flag' => true],
            ['text' => '', 'flag' => true],
        ]);

        self::assertSame(
            "\xEF\xBB\xBFText,Flag\r\n"
            . "'=1+1,true\r\n"
            . "\"'\tx\",false\r\n"
            . "\"'\rx\",\r\n"
            . "total,true\r\n"
            . "report,false\r\n"
            . "-5,true\r\n"
            . ",true\r\n",
            $csv,
        );
    }
}
