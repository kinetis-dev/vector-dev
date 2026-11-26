<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\ProductStatus;
use App\Tests\Vector\Fixtures\Level;
use DateTimeImmutable;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Vector\Fields\Tag;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Write\InputViolation;
use Kinetis\Vector\Write\ValueConverter;
use PHPUnit\Framework\TestCase;

/**
 * The converter's text parsing on its own: no resource, no database. An
 * integer or an identifier is digits and nothing after them, and an
 * instant is the ISO 8601 shapes the list grammar and imports accept.
 */
final class ValueConverterTest extends TestCase
{
    public function testAnIntegerIsDigitsWithNothingAfterThem(): void
    {
        $int = ['type' => 'number', 'valueType' => 'int'];

        self::assertSame(42, $this->converter()->fromQuery($int, '42'));
        self::assertSame(-7, $this->converter()->fromQuery($int, '-7'));

        foreach (["42\n", "-7\n", '42 ', '4x', '1234567890123456789'] as $raw) {
            self::assertSame('must be an integer.', $this->mismatch(fn () => $this->converter()->fromQuery($int, $raw)), json_encode($raw, JSON_THROW_ON_ERROR));
        }
    }

    public function testAnIdentifierIsAPositiveIntegerWithNothingAfterIt(): void
    {
        self::assertSame(42, $this->converter()->identifier('int', 42));
        self::assertSame(42, $this->converter()->identifier('int', '42'));
        self::assertSame("ab\n", $this->converter()->identifier('string', "ab\n"), 'a text key is any text');

        foreach (["42\n", '042', '0', '-1', '', '1234567890123456789'] as $raw) {
            self::assertSame('must be an identifier.', $this->mismatch(fn () => $this->converter()->identifier('int', $raw)), json_encode($raw, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * The shape check is split into a few simpler patterns; these pin the
     * strings the single pattern before it accepted and refused.
     */
    public function testAnInstantIsAnIso8601DateOrDateTime(): void
    {
        $instant = ['type' => 'datetime', 'valueType' => 'timestamp'];
        $accepted = [
            '2026-03-04' => '2026-03-04 00:00:00.000000',
            '2026-03-04Z' => '2026-03-04 00:00:00.000000',
            '2026-03-04+02:00' => '2026-03-03 22:00:00.000000',
            '2026-03-04 10:30' => '2026-03-04 10:30:00.000000',
            '2026-03-04T10:30:15' => '2026-03-04 10:30:15.000000',
            '2026-03-04T10:30:15.5' => '2026-03-04 10:30:15.500000',
            '2026-03-04T10:30:15.123456Z' => '2026-03-04 10:30:15.123456',
            '2026-03-04T10:30-0500' => '2026-03-04 15:30:00.000000',
            '2026-03-04T10:30:15.25+05:30' => '2026-03-04 05:00:15.250000',
        ];

        foreach ($accepted as $raw => $utc) {
            $value = $this->converter()->fromQuery($instant, $raw);
            self::assertInstanceOf(DateTimeImmutable::class, $value, $raw);
            self::assertSame($utc, $value->format('Y-m-d H:i:s.u'), $raw);
        }

        $refused = [
            '2026-3-04', '12026-03-04', '+2026-03-04', '2026-03-04x', '2026-03-04T', '2026-03-04T1:30', '2026-03-04T10:3',
            '2026-03-04T10:30:1', '2026-03-04T10:30.5', '2026-03-04T10:30:15.', '2026-03-04T10:30:15.1234567',
            '2026-03-04T10:30:15:00', '2026-03-04+2', '2026-03-04+02:0', '2026-03-04+02:00:00', '2026-03-04ZZ',
            '2026-03-04Z+02:00', '2026-03-04T10:30Z10:30', '2026-03-04T10:30+02:00T10:30', '2026-03-04 T10:30',
            "2026-03-04\n", "2026-03-04T10:30Z\n",
        ];

        foreach ($refused as $raw) {
            self::assertSame('must be an ISO 8601 date or date-time.', $this->mismatch(fn () => $this->converter()->fromQuery($instant, $raw)), $raw);
        }
    }

    /**
     * A select on an enum property: a query string matches an option by
     * its text, a JSON body must send the option's own JSON type, and the
     * entity receives the enum case. A value of the other backing type
     * once answered 500 instead of refusing it.
     */
    public function testASelectValueOfEitherBackingIsAChoiceOrARefusal(): void
    {
        $level = ['type' => 'select', 'valueType' => 'int', 'enum' => Level::class, 'nullable' => false, 'options' => [['value' => 1, 'label' => 'Low'], ['value' => 2, 'label' => 'High']]];
        $status = ['type' => 'select', 'valueType' => 'string', 'enum' => ProductStatus::class, 'nullable' => false, 'options' => array_map(static fn (ProductStatus $c): array => ['value' => $c->value, 'label' => $c->name], ProductStatus::cases())];

        self::assertSame(2, $this->converter()->fromQuery($level, '2'));
        self::assertSame('active', $this->converter()->fromQuery($status, 'active'));
        self::assertSame(Level::High, $this->converter()->convert($level, 2));
        self::assertSame(ProductStatus::Active, $this->converter()->convert($status, 'active'));
        self::assertSame(ProductStatus::Active, $this->converter()->convert($status, ProductStatus::Active), 'a default given as the case');

        foreach (['x', '3', '2x', ''] as $raw) {
            self::assertSame('is not one of the allowed values.', $this->mismatch(fn () => $this->converter()->fromQuery($level, $raw)), json_encode($raw, JSON_THROW_ON_ERROR));
        }

        foreach (['2', 'High', 3] as $raw) {
            self::assertSame('is not one of the allowed values.', $this->mismatch(fn () => $this->converter()->convert($level, $raw)), json_encode($raw, JSON_THROW_ON_ERROR));
        }

        foreach ([1, 'nope'] as $raw) {
            self::assertSame('is not one of the allowed values.', $this->mismatch(fn () => $this->converter()->convert($status, $raw)), json_encode($raw, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * A multiple select takes a list of known options: deduplicated and
     * kept in the order the options are declared, whatever order the
     * input had; an unknown value is refused at its index; an empty list
     * is null where the column allows it.
     */
    public function testAMultipleSelectTakesAListInDeclarationOrder(): void
    {
        $options = [['value' => 'web', 'label' => 'Web'], ['value' => 'retail', 'label' => 'Retail'], ['value' => 'wholesale', 'label' => 'Wholesale']];
        $channels = ['type' => 'select', 'multiple' => true, 'valueType' => 'json', 'enum' => null, 'nullable' => true, 'options' => $options];

        self::assertSame(['web', 'wholesale'], $this->converter()->convert($channels, ['wholesale', 'web', 'wholesale']));
        self::assertNull($this->converter()->convert($channels, []));
        self::assertSame([], $this->converter()->convert([...$channels, 'nullable' => false], []));
        self::assertSame(['web', 'retail'], $this->converter()->fromQuery($channels, '["retail","web"]'), 'an exported cell');
        self::assertSame(['web', 'retail'], $this->converter()->fromQuery($channels, 'retail | web'), 'a typed cell');

        try {
            $this->converter()->convert($channels, ['web', 'online']);
            self::fail('An unknown option was accepted.');
        } catch (InputViolation $e) {
            self::assertSame(['invalid_choice', ['1']], [$e->violation->code, $e->violation->path]);
        }

        foreach (['web', ['a' => 'web'], [['web']]] as $raw) {
            $this->expectNotAccepted(fn () => $this->converter()->convert($channels, $raw), json_encode($raw, JSON_THROW_ON_ERROR));
        }
    }

    /** Json and KeyValue fields write arrays to their JSON columns, never JSON text. */
    public function testJsonColumnsTakeDocumentsNotText(): void
    {
        $json = ['type' => 'json', 'valueType' => 'json', 'nullable' => true];
        $keyValue = ['type' => 'keyValue', 'valueType' => 'json', 'nullable' => true, 'props' => []];

        self::assertSame(['a' => [1, 2], 'b' => null], $this->converter()->convert($json, ['a' => [1, 2], 'b' => null]));
        self::assertSame([1, 'two'], $this->converter()->convert($json, [1, 'two']));
        self::assertSame(['carrier' => 'DHL'], $this->converter()->fromQuery($json, '{"carrier": "DHL"}'));
        self::assertSame(['size' => 'M', 'stock' => '3', 'used' => 'false'], $this->converter()->convert($keyValue, ['size' => 'M', 'stock' => 3, 'used' => false]));
        self::assertNull($this->converter()->convert($keyValue, []));

        foreach (['{"a": 1}', 42, true] as $raw) {
            self::assertSame('must be a JSON object or array.', $this->mismatch(fn () => $this->converter()->convert($json, $raw)), json_encode($raw, JSON_THROW_ON_ERROR));
        }

        self::assertSame('must be a JSON object or array.', $this->mismatch(fn () => $this->converter()->fromQuery($json, '"text"')));
    }

    /**
     * A JSON repeater's, a key-value's and a JSON field's cell is JSON
     * text: decoded when it holds what the field stores, refused by what
     * it should be otherwise, and a document deeper than any field may
     * nest is refused as too deep.
     */
    public function testAJsonTextCellIsDecodedOrRefused(): void
    {
        $repeater = ['type' => 'repeater', 'valueType' => 'json'];
        $keyValue = ['type' => 'keyValue', 'valueType' => 'json', 'props' => []];
        $json = ['type' => 'json', 'valueType' => 'json'];

        self::assertSame([['label' => 'x']], $this->converter()->fromQuery($repeater, '[{"label": "x"}]'));
        self::assertSame([], $this->converter()->fromQuery($repeater, '[]'));

        foreach (['{"label": "x"}', 'not json', '"text"'] as $raw) {
            self::assertSame('must be a JSON list of rows.', $this->mismatch(fn () => $this->converter()->fromQuery($repeater, $raw)), $raw);
        }

        self::assertSame(['size' => 'M'], $this->converter()->fromQuery($keyValue, '{"size": "M"}'));

        foreach (['not json', '7'] as $raw) {
            self::assertSame('must be a JSON object of text values.', $this->mismatch(fn () => $this->converter()->fromQuery($keyValue, $raw)), $raw);
        }

        self::assertSame([1, 2], $this->converter()->fromQuery($json, '[1, 2]'));
        self::assertSame('must be a JSON object or array.', $this->mismatch(fn () => $this->converter()->fromQuery($json, '{"a":')));
        self::assertSame('nests too deeply.', $this->mismatch(fn () => $this->converter()->fromQuery($json, str_repeat('[', 1000) . str_repeat(']', 1000))));
    }

    /** Tags are stored without the whitespace around them, Unicode spaces included, and keep the spaces inside. */
    public function testATagIsTrimmedAtBothEndsOnly(): void
    {
        self::assertSame('a b', Tag::trim("\u{00A0} a b\t"));
        self::assertSame('a b', Tag::trim('a b '));
        self::assertSame('a b', Tag::trim("\u{3000}a b"));
        self::assertSame('', Tag::trim(" \u{2003} "));
        self::assertSame("a\nb", Tag::trim("a\nb\n"));
    }

    /**
     * A property that keeps objects (`stdClass`, `array|stdClass`) takes a
     * stdClass tree: an object stays one, {} included, and a list a list,
     * whether the value arrives decoded with objects (a REST body, a CSV
     * cell) or as an associative array (an MCP tool's values).
     */
    public function testAPropertyThatKeepsObjectsTakesAStdClassTree(): void
    {
        $document = ['type' => 'json', 'valueType' => 'json', 'jsonObjects' => 'objectOrList', 'nullable' => true];
        $object = ['type' => 'json', 'valueType' => 'json', 'jsonObjects' => 'object', 'nullable' => false];
        $keyValue = ['type' => 'keyValue', 'valueType' => 'json', 'jsonObjects' => 'object', 'nullable' => false, 'props' => []];
        $encode = static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR);

        self::assertSame('{"box":{},"list":[1,{}]}', $encode($this->converter()->convert($document, json_decode('{"box":{},"list":[1,{}]}', false))));
        self::assertSame('[]', $encode($this->converter()->convert($document, [])), 'an empty array is a list where a list is allowed');
        self::assertSame('{"a":{"b":1},"c":[]}', $encode($this->converter()->convert($document, ['a' => ['b' => 1], 'c' => []])), 'an associative array is an object');
        self::assertSame('{}', $encode($this->converter()->convert($object, [])), 'an empty array is {} where only an object is');
        self::assertSame('must be a JSON object.', $this->mismatch(fn () => $this->converter()->convert($object, [1, 2])));
        self::assertSame('must be a JSON object or array.', $this->mismatch(fn () => $this->converter()->convert($document, 'text')));
        self::assertStringContainsString('NUL', $this->mismatch(fn () => $this->converter()->convert($document, ["\0a" => 1])));

        // an exported cell is the document's JSON text
        self::assertSame('{"box":{}}', $encode($this->converter()->fromQuery($document, '{"box": {}}')));
        self::assertSame('{}', $encode($this->converter()->fromQuery($keyValue, '{}')));

        self::assertSame('{}', $encode($this->converter()->convert($keyValue, new \stdClass())), 'an empty object kept where null is not allowed');
        self::assertSame('{"0":"zero"}', $encode($this->converter()->convert($keyValue, json_decode('{"0": "zero"}', false))), 'a digit key of an object');
        self::assertSame('{"size":"M","n":"3"}', $encode($this->converter()->convert($keyValue, ['size' => 'M', 'n' => 3])));
        self::assertNull($this->converter()->convert([...$keyValue, 'nullable' => true], new \stdClass()), 'cleared where null is allowed');
    }

    /**
     * required() is NotBlank: a list or an object that holds something is
     * present, an empty one is not_blank as a blank string is, and text is
     * held to NotBlank itself.
     */
    public function testRequiredTakesAnyNonEmptyListOrObject(): void
    {
        $required = ['rules' => [['constraint' => \Kinetis\Validation\Constraints\NotBlank::class, 'args' => []]]];
        $codes = fn (mixed $raw): array => array_map(static fn (\Kinetis\Validation\Violation $v): string => $v->code, $this->converter()->violationsOfRules($required, $raw));

        foreach (['a multiple select' => ['web'], 'tags' => ['a', 'b'], 'rows' => [['label' => 'x']], 'a JSON object' => ['carrier' => 'DHL'], 'a JSON list' => [1], 'a nested empty value' => [[]]] as $case => $raw) {
            self::assertSame([], $codes($raw), $case);
        }

        self::assertSame(['not_blank'], $codes([]), 'an empty list or object');
        self::assertSame(['not_blank'], $codes(''));
        self::assertSame(['not_blank'], $codes('   '), 'blank text is still blank');
        self::assertSame([], $codes('x'));
        self::assertSame([], $codes(0), 'a number that arrived');
        self::assertSame([], $codes(false));

        $maxed = ['rules' => [...$required['rules'], ['constraint' => \Kinetis\Validation\Constraints\MaxLength::class, 'args' => [3]]]];
        self::assertSame([], array_map(static fn (\Kinetis\Validation\Violation $v): string => $v->code, $this->converter()->violationsOfRules($maxed, 'abc')), 'the rules after it check the value itself');
    }

    /** A nullable JSON repeater with min() holds too few rows when cleared to null. */
    public function testNullIsTooFewRowsForAJsonRepeaterWithMin(): void
    {
        $repeater = ['type' => 'repeater', 'valueType' => 'json', 'nullable' => true, 'repeater' => ['mode' => 'json', 'min' => 1, 'max' => 5, 'rowFields' => []]];

        try {
            $this->converter()->convert($repeater, null);
            self::fail('null was accepted.');
        } catch (InputViolation $e) {
            self::assertSame(['count_out_of_range', ['min' => 1, 'max' => 5]], [$e->violation->code, $e->violation->parameters]);
        }

        self::assertNull($this->converter()->convert([...$repeater, 'repeater' => [...$repeater['repeater'], 'min' => 0]], null), 'without min() null clears it');
    }

    /** A tag's length is the stored tag's: Unicode spaces around it are trimmed first. */
    public function testATagIsMeasuredAsItIsStored(): void
    {
        $tags = ['type' => 'tag', 'valueType' => 'json', 'nullable' => false, 'tags' => ['maxLength' => 3, 'maxTags' => 10]];

        self::assertSame(['abc', 'de'], $this->converter()->convert($tags, ["\u{00A0}abc\u{3000}", "\u{2003}de"]));
        self::assertSame('may be at most 3 characters.', $this->mismatch(fn () => $this->converter()->convert($tags, ["\u{00A0}abcd"])));
    }

    private function expectNotAccepted(\Closure $conversion, string $label): void
    {
        try {
            $conversion();
        } catch (InputViolation) {
            return;
        }

        self::fail("{$label} was accepted.");
    }

    private function mismatch(\Closure $conversion): string
    {
        try {
            $conversion();
        } catch (InputViolation $e) {
            return $e->getMessage();
        }

        self::fail('The value was accepted.');
    }

    private function converter(): ValueConverter
    {
        $registry = VectorRegistry::fromArray(['version' => VectorRegistry::VERSION, 'resources' => [], 'settings' => [], 'dashboards' => []]);
        $managers = EntityManagerRegistry::create(OrmFactoryRegistry::create([], MetadataRegistry::fromClasses([])));

        return new ValueConverter($registry, $managers, new RequestScope(new AppScope()));
    }
}
