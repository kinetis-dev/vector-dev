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
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\VectorResource;

/**
 * A global search result shows the resource's subtitle field under its
 * title, and names the image field its thumbnail comes from when the
 * record has one.
 */
final class SearchResultTest extends VectorTestCase
{
    public function testAResultCarriesItsSubtitleAndThumbnail(): void
    {
        $row = $this->shop()->table('products')->select('id', 'sku', 'name')
            ->where('deleted_at', '=', null)->where('image_key', '!=', null)->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);

        $results = $this->client->get('/vector/search', query: ['q' => (string) $row['sku']], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($results);
        $product = array_values(array_filter($results['results'], static fn (array $r): bool => $r['resource'] === 'products' && (int) $r['id'] === (int) $row['id']))[0] ?? null;
        self::assertIsArray($product);
        self::assertSame((string) $row['name'], $product['title']);
        self::assertSame((string) $row['sku'], $product['subtitle']);
        self::assertSame('imageKey', $product['image']);

        $schema = $this->client->get('/vector/schema/products', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($schema);
        self::assertSame(['subtitle' => 'sku', 'image' => 'imageKey'], $schema['searchResult']);
    }

    public function testTheFieldsMustSuitTheirPlace(): void
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));
        $subtitle = new #[Resource(Product::class, slug: 'search-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')->searchable()];
            }

            public function searchSubtitle(): string
            {
                return 'nothing';
            }
        };
        $image = new #[Resource(Product::class, slug: 'search-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')->searchable()];
            }

            public function searchImage(): string
            {
                return 'name';
            }
        };

        foreach ([[$subtitle, 'is not a text, number or enum field'], [$image, 'is not an image field']] as [$resource, $message]) {
            try {
                (new ResourceCompiler($metadata, [$resource::class]))->compile();
                self::fail("compiled: {$message}");
            } catch (ResourceCompileException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}
