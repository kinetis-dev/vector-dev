<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id as EntityId;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Json;
use Kinetis\Vector\Fields\KeyValue;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\VectorResource;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * A `Json` or `KeyValue` field maps a property that keeps JSON objects:
 * `stdClass`, `?stdClass`, `array|stdClass` or `array|stdClass|null`, in
 * any union order. An `array` or `?array` property, which holds `{}` as
 * `[]`, is refused when the resource compiles, and a resource with no
 * fields of its own derives no field for one.
 */
final class JsonColumnTypesTest extends TestCase
{
    private const string ACCEPTED = 'declare the property stdClass, ?stdClass, array|stdClass or array|stdClass|null';

    public function testAnArrayPropertyIsRefused(): void
    {
        foreach ([
            [JsonOnList::class, 'JSON', 'list'],
            [JsonOnMaybeList::class, 'JSON', 'maybeList'],
            [KeyValueOnList::class, 'key-value', 'list'],
            [KeyValueOnMaybeList::class, 'key-value', 'maybeList'],
        ] as [$class, $kind, $property]) {
            self::assertSame(
                "Resource {$class}, field \"{$property}\": is a {$kind} field on the array property \"{$property}\", which would store {} as []: " . self::ACCEPTED,
                self::refusal($class),
            );
        }
    }

    public function testEveryPropertyThatKeepsObjectsIsAccepted(): void
    {
        $shapes = ['object' => 'object', 'maybeObject' => 'object', 'either' => 'objectOrList', 'maybeEither' => 'objectOrList', 'reordered' => 'objectOrList'];

        foreach ([JsonOnObjects::class => 'json', KeyValueOnObjects::class => 'keyValue'] as $class => $type) {
            $compiled = self::compile($class);
            $fields = array_column(reset($compiled)['fields'], null, 'name');

            foreach ($shapes as $property => $shape) {
                self::assertSame([$type, $shape], [$fields[$property]['type'], $fields[$property]['jsonObjects']], "{$type} on {$property}");
            }
        }
    }

    public function testADerivedResourceLeavesAnArrayPropertyOut(): void
    {
        $compiled = self::compile(DerivedJsonShapes::class);
        $names = array_column(reset($compiled)['fields'], 'name');

        self::assertSame(['id', 'object', 'maybeObject', 'either', 'maybeEither', 'reordered'], $names);
    }

    /**
     * @param class-string<VectorResource> $class
     * @return array<string, array<string, mixed>>
     */
    private static function compile(string $class): array
    {
        return (new ResourceCompiler(MetadataRegistry::fromClasses([JsonShapes::class]), [$class]))->compile();
    }

    /** @param class-string<VectorResource> $class */
    private static function refusal(string $class): string
    {
        try {
            self::compile($class);
        } catch (ResourceCompileException $e) {
            return $e->getMessage();
        }

        self::fail('The resource compiled.');
    }
}

/** One property of every JSON shape; never stored, only mapped. */
#[Entity(table: 'json_shapes')]
final class JsonShapes
{
    #[EntityId(generated: true)]
    public ?int $id = null;

    /** @var array<array-key, mixed> */
    public array $list = [];

    /** @var array<array-key, mixed>|null */
    public ?array $maybeList = null;

    public stdClass $object;

    public ?stdClass $maybeObject = null;

    /** @var array<array-key, mixed>|stdClass */
    public array|stdClass $either = [];

    /** @var array<array-key, mixed>|stdClass|null */
    public array|stdClass|null $maybeEither = null;

    /** @var array<array-key, mixed>|stdClass|null */
    public stdClass|array|null $reordered = null;

    public function __construct()
    {
        $this->object = new stdClass();
    }
}

#[Resource(JsonShapes::class, slug: 'json-on-list')]
final class JsonOnList extends VectorResource
{
    public function fields(): array
    {
        return [Id::make(), Json::make('list')];
    }
}

#[Resource(JsonShapes::class, slug: 'json-on-maybe-list')]
final class JsonOnMaybeList extends VectorResource
{
    public function fields(): array
    {
        return [Id::make(), Json::make('maybeList')];
    }
}

#[Resource(JsonShapes::class, slug: 'key-value-on-list')]
final class KeyValueOnList extends VectorResource
{
    public function fields(): array
    {
        return [Id::make(), KeyValue::make('list')];
    }
}

#[Resource(JsonShapes::class, slug: 'key-value-on-maybe-list')]
final class KeyValueOnMaybeList extends VectorResource
{
    public function fields(): array
    {
        return [Id::make(), KeyValue::make('maybeList')];
    }
}

#[Resource(JsonShapes::class, slug: 'json-on-objects')]
final class JsonOnObjects extends VectorResource
{
    public function fields(): array
    {
        return [Id::make(), Json::make('object'), Json::make('maybeObject'), Json::make('either'), Json::make('maybeEither'), Json::make('reordered')];
    }
}

#[Resource(JsonShapes::class, slug: 'key-value-on-objects')]
final class KeyValueOnObjects extends VectorResource
{
    public function fields(): array
    {
        return [Id::make(), KeyValue::make('object'), KeyValue::make('maybeObject'), KeyValue::make('either'), KeyValue::make('maybeEither'), KeyValue::make('reordered')];
    }
}

/** No fields of its own: derived from the entity. */
#[Resource(JsonShapes::class, slug: 'derived-json-shapes')]
final class DerivedJsonShapes extends VectorResource
{
}
