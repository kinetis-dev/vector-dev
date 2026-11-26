<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Testing\InteractsWithVector;

/**
 * A child field marked unique() is unique among a repeater's rows too:
 * two rows sending the same value are refused at the later row, before
 * anything is written.
 */
final class RepeaterUniqueRowTest extends VectorTestCase
{
    use InteractsWithVector;

    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $registry = $app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $data = $registry->toArray();

        // an order's lines may not share a quantity, as if the line resource marked it unique()
        foreach ($data['resources']['order-lines']['fields'] as $i => $field) {
            if ($field['name'] === 'quantity') {
                $data['resources']['order-lines']['fields'][$i]['unique'] = true;
            }
        }

        $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));
    }

    public function testRowsSharingAUniqueValueAreRefusedAtTheLaterRow(): void
    {
        $orders = $this->vector($this->realAdminHeaders())->resource('orders');
        [$p1, $p2] = array_map('intval', array_column($this->shop()->table('products')->select('id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->limit(2)->get(), 'id'));
        $id = $orders->create([
            'number' => 'ORD-UNQ-' . bin2hex(random_bytes(3)),
            'customer' => (int) $this->shop()->table('customers')->select('id')->orderBy('id', 'ASC')->value('id'),
            'status' => 'pending',
            'currency' => 'EUR',
            'placedAt' => '2026-09-26T09:00:00Z',
            'lines' => [['product' => $p1, 'quantity' => 1, 'unitPriceCents' => 100]],
        ])->assertCreated()->id();

        try {
            $this->client->patch("/vector/r/orders/{$id}", body: ['lines' => [
                ['product' => $p1, 'quantity' => 4, 'unitPriceCents' => 100],
                ['product' => $p2, 'quantity' => 3, 'unitPriceCents' => 100],
                ['product' => $p2, 'quantity' => 4, 'unitPriceCents' => 100],
            ]], headers: $this->realAdminHeaders())
                ->assertValidationError('lines', 2, 'quantity');

            $rows = $this->client->get("/vector/r/orders/{$id}/lines/rows", headers: $this->realAdminHeaders())->assertOk()->json();
            self::assertIsArray($rows);
            self::assertSame([1], array_column($rows['data'], 'quantity'), 'nothing was written');
        } finally {
            $orders->batch('delete', [$id])->assertOk();
        }
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}
