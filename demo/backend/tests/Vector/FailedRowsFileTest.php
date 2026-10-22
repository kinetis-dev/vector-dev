<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Vector\Import\CsvReader;
use Kinetis\Vector\Store\JobStore;
use PHPUnit\Framework\TestCase;

/**
 * An import's failed-rows file fits the job row: past the most a job
 * file holds, it keeps the rows that fit, whole, and says on the last
 * one how many it left out. No database: the document is a pure
 * function of its rows.
 */
final class FailedRowsFileTest extends TestCase
{
    public function testAFailedRowsFileLargerThanAJobFileHoldsKeepsTheRowsThatFit(): void
    {
        $rows = [];

        for ($i = 0; $i < 14_000; $i++) {
            $rows[] = ['sku' => sprintf('SKU-%05d', $i), 'name' => str_repeat('x', 1000), 'error' => 'sku is taken.'];
        }

        $csv = CsvReader::toCsv(['sku', 'name', 'error'], $rows, JobStore::MAX_FILE_BYTES);

        self::assertLessThanOrEqual(JobStore::MAX_FILE_BYTES, strlen($csv));
        self::assertStringEndsWith("\r\n", $csv);

        $read = CsvReader::fromString($csv);
        $kept = iterator_to_array($read->rows(), false);
        self::assertSame(['sku', 'name', 'error'], $read->headers());
        self::assertGreaterThan(12_000, count($kept));
        self::assertLessThan(count($rows), count($kept));

        // every row but the last is the failed row as it was; the last carries the note
        $last = array_pop($kept);
        self::assertIsArray($last);
        self::assertSame(array_slice($rows, 0, count($kept)), $kept);
        self::assertSame($rows[count($kept)]['sku'], $last['sku']);
        self::assertSame(
            sprintf('sku is taken. — %d more failed rows are not in this file, which holds at most 12 MiB.', count($rows) - count($kept) - 1),
            $last['error'],
        );
    }

    public function testAFailedRowsFileWithinTheLimitIsWhole(): void
    {
        $rows = [['sku' => 'SKU-1', 'error' => 'sku is taken.'], ['sku' => 'SKU-2', 'error' => 'name is required.']];

        self::assertSame(CsvReader::toCsv(['sku', 'error'], $rows), CsvReader::toCsv(['sku', 'error'], $rows, JobStore::MAX_FILE_BYTES));
    }
}
