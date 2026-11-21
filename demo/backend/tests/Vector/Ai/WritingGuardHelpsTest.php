<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\VectorAi\Writing\WritingGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * WritingGuard::helps(), the field's own half of writing help's rules:
 * only its types; a unique string is an identifier, not prose, and is
 * off; `ai.writing` true or false wins either way, but never widens the
 * types; and `ai.send` plays no part.
 */
final class WritingGuardHelpsTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function fields(): iterable
    {
        yield 'a string' => [['name' => 'title', 'type' => 'string', 'unique' => false], true];
        yield 'a text, markdown or rich-text field' => [['name' => 'notes', 'type' => 'text', 'unique' => false], true];
        yield 'a unique rich-text field' => [['name' => 'body', 'type' => 'richtext', 'unique' => true], true];
        yield 'a unique string' => [['name' => 'sku', 'type' => 'string', 'unique' => true], false];
        yield 'a unique string that opts in' => [['name' => 'sku', 'type' => 'string', 'unique' => true, 'meta' => ['ai.writing' => true]], true];
        yield 'a text field that opts out' => [['name' => 'notes', 'type' => 'text', 'unique' => false, 'meta' => ['ai.writing' => false]], false];
        yield 'a string that opts out but is sent' => [['name' => 'title', 'type' => 'string', 'meta' => ['ai.send' => true, 'ai.writing' => false]], false];
        yield 'a string kept from the model' => [['name' => 'title', 'type' => 'string', 'meta' => ['ai.send' => false]], true];
        yield 'a number that opts in' => [['name' => 'stock', 'type' => 'number', 'meta' => ['ai.writing' => true]], false];
        yield 'an override that is no boolean' => [['name' => 'sku', 'type' => 'string', 'unique' => true, 'meta' => ['ai.writing' => 'yes']], false];
    }

    /** @param array<string, mixed> $field */
    #[DataProvider('fields')]
    public function testWhetherWritingHelpIsOnForAField(array $field, bool $helps): void
    {
        self::assertSame($helps, WritingGuard::helps($field));
    }

    public function testTheOverrideIsItsOwnMetaKey(): void
    {
        self::assertSame('ai.writing', WritingGuard::META_KEY);
    }
}
