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
use Kinetis\Vector\Fields\Markdown;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Testing\InteractsWithVector;
use Kinetis\Vector\VectorResource;

/**
 * Inline cell editing's changeset: `POST …/changes` writes different
 * values to different records in one transaction, each checked against
 * the version it was read at; a violation names its change and field.
 */
final class InlineChangesTest extends VectorTestCase
{
    use InteractsWithVector;

    public function testInlineFieldsArePublishedAndRecordsCarryTheirVersion(): void
    {
        $fields = array_column($this->vector($this->adminHeaders())->schema('products')->assertOk()->json()['fields'], null, 'name');
        self::assertTrue($fields['stock']['inline']);
        self::assertFalse($fields['name']['inline']);

        $record = $this->vector($this->adminHeaders())->resource('products')->list(perPage: 1)->records()[0];
        self::assertIsInt($record['_version'], 'a list row carries the optimistic-lock token a change sends back');
    }

    public function testAChangesetWritesEveryRecordInOneTransaction(): void
    {
        $products = $this->vector($this->realAdminHeaders())->resource('products');
        $rows = $products->list(filter: ['status' => ['eq' => 'active']], sort: 'id', perPage: 2)->records();
        [$a, $b] = $rows;

        try {
            $written = $this->client->post('/vector/r/products/changes', body: ['changes' => [
                ['id' => $a['id'], 'version' => $a['_version'], 'values' => ['stock' => 111]],
                ['id' => $b['id'], 'version' => $b['_version'], 'values' => ['stock' => 222, 'isFeatured' => !$b['isFeatured']]],
            ]], headers: $this->realAdminHeaders())->assertOk()->json();
            self::assertIsArray($written);
            self::assertSame([111, 222], array_column($written['data'], 'stock'));
            self::assertSame($b['_version'] + 1, $written['data'][1]['_version']);
            self::assertSame(111, $products->audit($a['id'])->records()[0]['changes']['stock']['to']);

            // one bad value refuses the whole changeset, naming its change and field
            $this->client->post('/vector/r/products/changes', body: ['changes' => [
                ['id' => $a['id'], 'values' => ['stock' => 5]],
                ['id' => $b['id'], 'values' => ['stock' => 'lots']],
            ]], headers: $this->realAdminHeaders())->assertValidationError('changes', 1, 'stock');
            self::assertSame(111, $this->stock((int) $a['id']));

            // a value only the database refuses is still attributed to the one change that wrote it
            $this->client->post('/vector/r/products/changes', body: ['changes' => [
                ['id' => $a['id'], 'values' => ['isFeatured' => true]],
                ['id' => $b['id'], 'values' => ['stock' => 99_999_999_999]],
            ]], headers: $this->realAdminHeaders())->assertValidationError('changes', 1, 'stock');
            self::assertSame(222, $this->stock((int) $b['id']));

            // a stale version refuses it too
            $products->changes([['id' => $a['id'], 'version' => $a['_version'], 'values' => ['stock' => 6]]])->assertStatus(409);
            self::assertSame(111, $this->stock((int) $a['id']));
        } finally {
            $this->shop()->table('products')->where('id', '=', $a['id'])->update(['stock' => $a['stock']]);
            $this->shop()->table('products')->where('id', '=', $b['id'])->update(['stock' => $b['stock'], 'is_featured' => $b['isFeatured'] ? 1 : 0]);
        }

        $this->client->post('/vector/r/products/changes', body: ['changes' => 'x'], headers: $this->realAdminHeaders())->assertValidationError('changes');
    }

    public function testOnlySimpleEditableFieldsMayBeInline(): void
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));
        $bad = new #[Resource(Product::class, slug: 'inline-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Markdown::make('description')->inlineEditable()];
            }
        };

        $this->expectException(ResourceCompileException::class);
        $this->expectExceptionMessage('is a markdown field, which cannot be edited inline');
        (new ResourceCompiler($metadata, [$bad::class]))->compile();
    }

    /** @phpstan-impure it reads the row as it is now */
    private function stock(int $id): int
    {
        return (int) $this->shop()->table('products')->select('stock')->where('id', '=', $id)->value('stock');
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}
