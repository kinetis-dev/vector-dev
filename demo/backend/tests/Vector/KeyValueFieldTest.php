<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Product;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\KeyValue;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Testing\InteractsWithVector;
use Kinetis\Vector\VectorResource;

/**
 * A key-value field: a JSON object of text values in a string column,
 * an object on the wire in both directions.
 */
final class KeyValueFieldTest extends VectorTestCase
{
    use InteractsWithVector;

    public function testAStoredObjectReadsAsTextValues(): void
    {
        $products = $this->vector($this->realAdminHeaders())->resource('products');
        [$id, $stored] = $this->product();

        $attributes = array_column($this->vector($this->adminHeaders())->schema('products')->json()['fields'], null, 'name')['attributes'];
        self::assertSame('keyValue', $attributes['type']);

        try {
            $this->shop()->table('products')->where('id', '=', $id)->update(['attributes' => '{"color": "silver", "warrantyMonths": 24, "refurbished": false}']);
            $shown = $this->client->get("/vector/r/products/{$id}", headers: $this->realAdminHeaders())->assertOk()->json();
            self::assertIsArray($shown);
            self::assertEquals(['color' => 'silver', 'warrantyMonths' => '24', 'refurbished' => 'false'], $shown['data']['attributes']);

            $products->update($id, ['attributes' => ['size' => 'M', ' finish ' => 'matte', '0' => 'zero']])->assertOk();
            // a JSON column orders keys its own way; the entries are what count
            self::assertEquals(['size' => 'M', 'finish' => 'matte', '0' => 'zero'], json_decode((string) $this->stored($id), true), 'keys trimmed');
            $raw = $this->client->get("/vector/r/products/{$id}", headers: $this->realAdminHeaders())->assertOk()->body();
            self::assertMatchesRegularExpression('/"attributes":\{[^}]*"0":"zero"/', $raw, 'a digit key is still an object key');

            $products->update($id, ['attributes' => []])->assertOk();
            self::assertNull($this->stored($id), 'an empty object clears a nullable column');
            self::assertStringContainsString('"attributes":null', $this->client->get("/vector/r/products/{$id}", headers: $this->realAdminHeaders())->body());
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['attributes' => $stored]);
        }
    }

    public function testOnlyAnObjectOfTextValuesIsAccepted(): void
    {
        $products = $this->vector($this->realAdminHeaders())->resource('products');
        [$id, $stored] = $this->product();

        try {
            $products->update($id, ['attributes' => ['a', 'b']])->assertInvalid('attributes', 'type_mismatch');
            $products->update($id, ['attributes' => ['  ' => 'x']])->assertInvalid('attributes', 'invalid_key');
            $products->update($id, ['attributes' => ['a' => ['b' => 1]]])->assertInvalid('attributes', 'type_mismatch');
            $products->update($id, ['attributes' => array_combine(array_map(static fn (int $i): string => "k{$i}", range(1, 51)), array_fill(0, 51, 'v'))])
                ->assertInvalid('attributes', 'too_many_entries');
            self::assertSame($stored === null ? null : json_encode(json_decode($stored, false), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $this->stored($id), 'nothing was written');
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['attributes' => $stored]);
        }
    }

    public function testItIsAStringColumnThatIsNeitherSortedNorFiltered(): void
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));
        $sorted = new #[Resource(Product::class, slug: 'kv-sorted')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), KeyValue::make('attributes')->sortable()];
            }
        };
        $number = new #[Resource(Product::class, slug: 'kv-number')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), KeyValue::make('stock')];
            }
        };

        foreach ([[$sorted, 'cannot be sorted'], [$number, 'stores its JSON in a string property']] as [$resource, $message]) {
            try {
                (new ResourceCompiler($metadata, [$resource::class]))->compile();
                self::fail("compiled: {$message}");
            } catch (ResourceCompileException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    /** @return array{0: int, 1: string|null} */
    private function product(): array
    {
        $row = $this->shop()->table('products')->select('id', 'attributes')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);

        return [(int) $row['id'], $row['attributes'] === null ? null : (string) $row['attributes']];
    }

    private function stored(int $id): ?string
    {
        $value = $this->shop()->table('products')->select('attributes')->where('id', '=', $id)->value('attributes');

        return $value === null ? null : json_encode(json_decode((string) $value, false), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}
