<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Mcp\ToolDefinition;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\VectorAi\Approvals\ToolArguments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ToolArguments::validate() on a tool whose parameters are a DTO, a
 * nullable DTO and a scalar with a default, database-free: each
 * violation by path and code, in the order of the tool's parameters and
 * then of the arguments it does not take.
 */
final class ToolArgumentsTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, list<array{list<string|int>, string}>}> */
    public static function arguments(): iterable
    {
        yield 'every argument valid' => [['item' => ['count' => 2], 'extra' => null, 'limit' => 5], []];
        yield 'the defaulted and nullable ones left out' => [['item' => ['count' => 2]], []];
        yield 'a null nullable DTO' => [['item' => ['count' => 2], 'extra' => null], []];
        yield 'a null DTO that is not nullable' => [['item' => null], [[['item'], 'null_not_allowed']]];
        yield 'a DTO given as text' => [['item' => 'two'], [[['item'], 'type_mismatch']]];
        yield 'a DTO given as a number' => [['item' => 2, 'extra' => 'x'], [[['item'], 'type_mismatch'], [['extra'], 'type_mismatch']]];
        yield 'a DTO missing its field' => [['item' => []], [[['count'], 'required']]];
        yield 'a missing DTO, then an argument the tool does not take' => [['other' => 1], [[['item'], 'required'], [['other'], 'unexpected_field']]];
    }

    /**
     * @param array<string, mixed> $inputs
     * @param list<array{list<string|int>, string}> $expected
     */
    #[DataProvider('arguments')]
    public function testEachViolationIsNamedInOrder(array $inputs, array $expected): void
    {
        $tool = new ToolDefinition('fixture_tool', 'A fixture.', ToolArgumentsFixtureTool::class, 'call', []);

        try {
            ToolArguments::validate($tool, $inputs);
            $violations = [];
        } catch (ValidationException $e) {
            $violations = array_map(static fn (Violation $v): array => [$v->path, $v->code], $e->violations);
        }

        self::assertSame($expected, $violations);
    }
}

/** @internal a tool for ToolArgumentsTest */
final class ToolArgumentsFixtureTool
{
    public function call(ToolArgumentsFixtureItem $item, ?ToolArgumentsFixtureItem $extra = null, int $limit = 10): string
    {
        return (string) ($item->count + ($extra->count ?? 0) + $limit);
    }
}

/** @internal a DTO for ToolArgumentsTest */
final readonly class ToolArgumentsFixtureItem
{
    public function __construct(public int $count) {}
}
