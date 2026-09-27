<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Tag;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\VectorResource;

/**
 * What the admin's Duplicate needs from the descriptor: which fields hold
 * unique values (a copy starts without them) and whether a resource may be
 * duplicated at all.
 */
final class ReplicateTest extends VectorTestCase
{
    public function testUniqueFieldsAreMarked(): void
    {
        $fields = array_column($this->client->get('/vector/schema/products', headers: $this->adminHeaders())->assertOk()->json()['fields'], null, 'name');

        self::assertTrue($fields['sku']['unique']);
        self::assertTrue($fields['slug']['unique']);
        self::assertFalse($fields['name']['unique']);
        self::assertFalse($fields['priceCents']['unique']);
    }

    public function testAResourceIsReplicableUnlessItOptsOut(): void
    {
        $this->client->get('/vector/schema/products', headers: $this->adminHeaders())->assertOk()->assertJsonPath('replicable', true);

        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));
        $once = new #[Resource(Tag::class, slug: 'one-off-tags')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')->unique()];
            }

            public function replicable(): bool
            {
                return false;
            }
        };
        $descriptor = new ResourceDescriptor((new ResourceCompiler($metadata, [$once::class]))->compile()['one-off-tags']);

        self::assertFalse($descriptor->replicable());
        self::assertFalse($descriptor->toArray()['replicable']);
        self::assertTrue($descriptor->toArray()['fields'][1]['unique']);
    }

    public function testACopyIsCreatedThroughTheOrdinaryCreate(): void
    {
        $source = $this->client->get('/vector/r/products', query: ['filter' => ['sku' => ['eq' => 'SKU-00042']]], headers: $this->adminHeaders())->assertOk()->json()['data'][0];
        $record = $this->client->get("/vector/r/products/{$source['id']}", headers: $this->adminHeaders())->assertOk()->json()['data'];

        // what the admin's form sends for a copy: every value but the unique ones, and a new SKU
        $values = [
            'name' => $record['name'] . ' (copy)', 'status' => 'draft', 'category' => $record['category']['id'], 'priceCents' => $record['priceCents'],
            'currency' => $record['currency'], 'stock' => $record['stock'], 'weightKg' => $record['weightKg'], 'isFeatured' => false,
        ];
        $copy = $this->client->post('/vector/r/products', body: [...$values, 'sku' => 'SKU-COPY-1', 'slug' => 'copy-of-42'], headers: $this->adminHeaders())
            ->assertCreated()->json()['data'];

        try {
            self::assertNotSame($source['id'], $copy['id']);
            self::assertSame($record['priceCents'], $copy['priceCents']);

            // the unique value cannot be copied as it is
            $this->client->post('/vector/r/products', body: [...$values, 'sku' => 'SKU-00042', 'slug' => 'copy-of-42-again'], headers: $this->adminHeaders())
                ->assertStatus(422)->assertJsonPath('errors.0.path', ['sku']);
        } finally {
            $this->client->request('DELETE', "/vector/r/products/{$copy['id']}", headers: $this->adminHeaders(), query: ['force' => '1'])->assertStatus(204);
        }
    }
}
