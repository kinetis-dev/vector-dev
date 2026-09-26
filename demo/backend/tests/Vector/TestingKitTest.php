<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Vector\Testing\InteractsWithVector;

/**
 * Vector's testing kit as a host application uses it: request builders
 * per endpoint and assertions in Vector's terms, through the
 * application's own middleware and policies.
 */
final class TestingKitTest extends VectorTestCase
{
    use InteractsWithVector;

    /** A 1×1 PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testTheContractReadsInVectorsTerms(): void
    {
        $admin = $this->vector($this->realAdminHeaders());

        $admin->schema('products')->assertOk()->assertHasField('sku')->assertLacksField('nothing');
        $admin->resource('products')
            ->list(filter: ['status' => ['eq' => 'archived']], sort: '-priceCents', perPage: 5)
            ->assertOk()
            ->assertEveryRecord(static fn (array $p): bool => $p['status'] === 'archived');

        $tags = $admin->resource('tags');
        $name = 'kit-' . bin2hex(random_bytes(3));
        $id = $tags->create(['name' => $name, 'color' => '#123456'])->assertCreated()->assertRecord(['name' => $name])->id();

        try {
            $tags->list(q: $name)->assertTotal(1)->assertIds([$id]);
            $tags->show($id)->assertOk()->assertRecord(['color' => '#123456'])->assertPermission('update', true);
            $tags->update($id, ['color' => 'not-a-colour'])->assertInvalid('color');
            $tags->update($id, ['color' => '#654321'])->assertOk()->assertRecord(['color' => '#654321']);
            $tags->batch('update', [$id], fields: ['color' => '#abcdef'])->assertOk();
            $tags->show($id)->assertRecord(['color' => '#abcdef']);
            self::assertSame(['update', 'update', 'create'], array_column($tags->audit($id)->assertOk()->records(), 'event'));
        } finally {
            $tags->delete($id)->assertStatus(204);
        }

        $tags->show($id)->assertNotFound();
    }

    public function testRelationsActionsAndFiles(): void
    {
        $admin = $this->vector($this->realAdminHeaders());
        $products = $admin->resource('products');
        $product = $products->list(filter: ['status' => ['eq' => 'draft']], perPage: 1)->records()[0];
        $tagIds = $admin->resource('tags')->list(perPage: 50)->ids();
        $held = $products->related($product['id'], 'tags', ['perPage' => 50])->ids();
        $free = array_values(array_diff($tagIds, $held))[0];

        $products->attach($product['id'], 'tags', [$free])->assertOk();
        $products->related($product['id'], 'tags', ['perPage' => 50])->assertListed($free);
        $products->detach($product['id'], 'tags', [$free])->assertOk();
        $products->related($product['id'], 'tags', ['perPage' => 50])->assertNotListed($free);

        $jobId = $products->action('reprice-products', [$product['id']], ['percent' => 0])->assertQueued();
        self::assertSame('done', $admin->job($jobId)->assertOk()->json()['data']['status']);

        $uploaded = $products->upload('imageKey', (string) base64_decode(self::PNG, true), 'kit.png')->assertOk()->json();
        self::assertStringStartsWith('vector/products/imageKey/', $uploaded['key']);
        $products->upload('imageKey', 'plain text', 'kit.png')->assertInvalid('file', 'invalid_file');
    }

    public function testWhoMayDoWhat(): void
    {
        $this->vector()->resource('products')->list()->assertUnauthenticated();
        $this->vector($this->editorHeaders())->resource('products')->list()->assertForbidden();
        $this->vector($this->adminHeaders())->resource('products')->create([])->assertInvalid('sku', 'required');
    }
}
