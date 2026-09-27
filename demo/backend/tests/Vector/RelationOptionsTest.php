<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;

/**
 * A relation's choice list tells records apart as global search does:
 * each option carries the target's search subtitle and, when the record
 * has one, its search image field — from the same one statement.
 */
final class RelationOptionsTest extends VectorTestCase
{
    public function testACustomerOptionCarriesItsEmail(): void
    {
        $customer = $this->shop()->table('customers')->select('id', 'name', 'email')->orderBy('id', 'ASC')->first();
        self::assertIsArray($customer);

        $options = $this->client->get('/vector/r/orders/fields/customer/options', ['ids' => (string) $customer['id']], $this->adminHeaders())->assertOk()->json()['data'];

        self::assertSame([['id' => (int) $customer['id'], 'label' => $customer['name'], 'subtitle' => $customer['email']]], $options);
    }

    public function testAProductOptionCarriesItsSkuAndItsImageFieldWhenItHasAnImage(): void
    {
        $withImage = $this->shop()->table('products')->select('id', 'sku')->where('image_key', '!=', null)->where('deleted_at', '=', null)->orderBy('id', 'ASC')->first();
        $withoutImage = $this->shop()->table('products')->select('id', 'sku')->where('image_key', '=', null)->where('deleted_at', '=', null)->orderBy('id', 'ASC')->first();
        self::assertIsArray($withImage, 'the demo seeds product images');
        self::assertIsArray($withoutImage);

        $options = $this->client->get('/vector/r/order-lines/fields/product/options', ['ids' => "{$withImage['id']},{$withoutImage['id']}"], $this->adminHeaders())->assertOk()->json()['data'];
        $options = array_column($options, null, 'id');

        self::assertSame($withImage['sku'], $options[(int) $withImage['id']]['subtitle']);
        self::assertSame('imageKey', $options[(int) $withImage['id']]['image']);
        self::assertSame($withoutImage['sku'], $options[(int) $withoutImage['id']]['subtitle']);
        self::assertNull($options[(int) $withoutImage['id']]['image']);
    }

    public function testAResourceWithoutASubtitleAnswersIdAndLabelOnly(): void
    {
        $options = $this->client->get('/vector/r/products/fields/category/options', ['q' => ''], $this->adminHeaders())->assertOk()->json()['data'];

        self::assertNotEmpty($options);
        self::assertSame(['id', 'label'], array_keys($options[0]));
    }

    public function testTheSubtitleAndTheImageCostNoStatement(): void
    {
        $options = fn (string $path): int => $this->countStatements(fn () => $this->client->get($path, ['q' => 'a'], $this->adminHeaders())->assertOk());
        $options('/vector/r/products/fields/category/options'); // warm up whatever a first request loads

        self::assertSame(
            $options('/vector/r/products/fields/category/options'),
            $options('/vector/r/order-lines/fields/product/options'),
            'a product option, with subtitle and image, costs what a category option costs',
        );
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}
