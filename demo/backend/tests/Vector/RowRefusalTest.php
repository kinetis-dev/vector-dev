<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Write\RecordWriter;
use PHPUnit\Framework\TestCase;

/**
 * Which repeater row a unique violation is reported at, from the driver's
 * own message: the table is matched against the key's name alone, as a
 * whole, and the row by the value the key holds for the column, never by
 * a substring of the message.
 */
final class RowRefusalTest extends TestCase
{
    public function testTheKeyNameAloneNamesTheTableAsAWhole(): void
    {
        // `lines` is declared first: matching anywhere in the message picked it
        $plans = [self::plan('notes', 'lines', [['sku' => 'x']]), self::plan('lines', 'order_lines', [['sku' => 'a'], ['sku' => 'lines']])];

        self::assertSame(
            [['lines', 1, 'sku'], 'unique'],
            self::refusal($plans, "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'lines' for key 'order_lines.order_lines_sku_unique'"),
            'MySQL: the table before the index name',
        );
        self::assertSame(
            [['lines', 1, 'sku'], 'unique'],
            self::refusal($plans, 'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "order_lines_sku_key" DETAIL:  Key (sku)=(lines) already exists.'),
            'PostgreSQL: the constraint named after its table',
        );
        self::assertNull(
            self::refusal([self::plan('notes', 'notes', [['sku' => 'notes']])], "Duplicate entry 'notes' for key 'other_notes.other_notes_sku_unique'"),
            'the table in the duplicated value, or inside another table\'s name, is no match',
        );
        self::assertNull(self::refusal([self::plan('notes', 'notes', [['sku' => 'notes']])], 'duplicate key value violates unique constraint "subnotes_sku_key" DETAIL:  Key (sku)=(notes) already exists.'));
    }

    public function testTheRowIsTheOneHoldingTheColumnsOwnValue(): void
    {
        $dashed = [self::plan('lines', 'order_lines', [['sku' => 'ab-cd'], ['sku' => 'ab']])];
        self::assertSame(
            [['lines', 0, 'sku'], 'unique'],
            self::refusal($dashed, "Duplicate entry 'ab-cd' for key 'order_lines.order_lines_sku_unique'"),
            'a value holding a dash is matched whole before any part of it',
        );

        // a composite key: the other column's value (12) is no row's code
        $composite = [self::plan('lines', 'order_lines', [['code' => '5'], ['code' => '12']])];
        self::assertSame(
            [['lines', 0, 'code'], 'unique'],
            self::refusal($composite, 'duplicate key value violates unique constraint "order_lines_code_order_id_key" DETAIL:  Key (code, order_id)=(5, 12) already exists.'),
        );
        self::assertSame(
            [['lines', 1, 'code'], 'unique'],
            self::refusal($composite, "Duplicate entry '7-12' for key 'order_lines.order_lines_code_batch_unique'"),
            'MySQL joins a composite key by dashes: one whole part of it',
        );
    }

    /**
     * @param list<array<string, mixed>> $rows each row's values
     * @return array<string, mixed>
     */
    private static function plan(string $field, string $table, array $rows): array
    {
        $child = new ResourceDescriptor(['fields' => [], 'table' => $table, 'columns' => ['sku' => 'sku', 'code' => 'code', 'order' => 'order_id']]);

        return ['field' => $field, 'child' => $child, 'rows' => array_map(static fn (array $values): array => ['values' => $values], $rows)];
    }

    /**
     * @param list<array<string, mixed>> $plans
     * @return array{0: list<int|string>, 1: string}|null
     */
    private static function refusal(array $plans, string $message): ?array
    {
        $refusal = (new \ReflectionMethod(RecordWriter::class, 'rowRefusal'))->invoke(null, $plans, $message);

        if ($refusal === null) {
            return null;
        }

        self::assertInstanceOf(ValidationException::class, $refusal);
        self::assertCount(1, $refusal->violations);

        return [$refusal->violations[0]->path, $refusal->violations[0]->code];
    }
}
