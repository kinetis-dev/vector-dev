<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Customer;
use App\Domain\Order;
use App\Domain\OrderLine;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\HasMany;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Store\AuditStore;
use Kinetis\Vector\Testing\InteractsWithVector;
use Kinetis\Vector\VectorResource;

/**
 * A repeater writes an owned relation's rows with their parent: the
 * whole list in one transaction, each row validated, authorized, hooked
 * and audited as its child resource's own create, update or delete.
 */
final class RepeaterTest extends VectorTestCase
{
    use InteractsWithVector;

    private RepeaterPolicy $policy;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->policy = new RepeaterPolicy();
        $app->instance(AllowAllPolicy::class, $this->policy);
    }

    public function testTheSchemaDescribesTheRowsAForm(): void
    {
        $lines = array_column($this->vector($this->adminHeaders())->schema('orders')->assertOk()->json()['fields'], null, 'name')['lines'];
        self::assertSame(['fields' => ['product', 'quantity', 'unitPriceCents'], 'min' => 1, 'max' => 50], $lines['repeater']);
        self::assertContains('create', $lines['views']);
        self::assertContains('update', $lines['views']);
    }

    public function testAnOrderIsWrittenWithItsLines(): void
    {
        $orders = $this->vector($this->realAdminHeaders())->resource('orders');
        [$p1, $p2, $p3] = $this->productIds(3);
        $id = $orders->create($this->order([
            ['product' => $p1, 'quantity' => 2, 'unitPriceCents' => 150],
            ['product' => $p2, 'quantity' => 1, 'unitPriceCents' => 1000],
        ]))->assertCreated()->assertRecord(['totalCents' => 1300])->id();

        try {
            $rows = $this->rows($id);
            self::assertSame([$p1, $p2], array_map(static fn (array $row): int => $row['product']['id'], $rows));
            self::assertSame('create', $this->vector($this->realAdminHeaders())->resource('order-lines')->audit($rows[0]['id'])->records()[0]['event']);

            // a line is priced in its order's currency, which its order relation carries
            $line = $this->vector($this->realAdminHeaders())->resource('order-lines')->list(filter: ['order' => ['eq' => (string) $id]])->records()[0];
            self::assertSame('EUR', $line['order']['currency']);
            $unitPrice = array_column($this->vector($this->adminHeaders())->schema('order-lines')->json()['fields'], null, 'name');
            self::assertSame('order.currency', $unitPrice['unitPriceCents']['props']['currencyField']);
            self::assertArrayNotHasKey('carry', $unitPrice['order']['relation'], 'which column carries it stays on the server');

            // keep the first line with a new quantity, drop the second, add a third
            $orders->update($id, ['lines' => [
                ['id' => $rows[0]['id'], 'product' => $p1, 'quantity' => 5, 'unitPriceCents' => 150],
                ['product' => $p3, 'quantity' => 1, 'unitPriceCents' => 99],
            ]])->assertOk()->assertRecord(['totalCents' => 849]);

            $after = $this->rows($id);
            self::assertSame([$rows[0]['id'], 5], [$after[0]['id'], $after[0]['quantity']], 'a row with an id updates that line');
            self::assertSame($p3, $after[1]['product']['id']);
            self::assertSame(0, $this->shop()->table('order_lines')->where('id', '=', $rows[1]['id'])->count(), 'a line left out is deleted');

            $lines = $this->vector($this->realAdminHeaders())->resource('order-lines');
            self::assertEquals(['from' => 2, 'to' => 5], $lines->audit($rows[0]['id'])->records()[0]['changes']['quantity']);
            $audit = $this->app->get(AuditStore::class);
            self::assertInstanceOf(AuditStore::class, $audit);
            self::assertSame('delete', $audit->trail('order-lines', (string) $rows[1]['id'])[0]['event'], 'a deleted line keeps its history');

            // an update that leaves the lines alone keeps them and the total
            $orders->update($id, ['notes' => 'Leave at the door'])->assertOk()->assertRecord(['totalCents' => 849]);
            self::assertCount(2, $this->rows($id));
        } finally {
            $orders->batch('delete', [$id])->assertOk();
        }
    }

    public function testEveryRowIsValidatedAndOneBadRowWritesNothing(): void
    {
        $orders = $this->vector($this->realAdminHeaders())->resource('orders');
        [$p1] = $this->productIds(1);
        $id = $orders->create($this->order([['product' => $p1, 'quantity' => 1, 'unitPriceCents' => 100]]))->assertCreated()->id();
        $line = $this->rows($id)[0]['id'];

        try {
            $orders->update($id, ['lines' => [
                ['id' => $line, 'product' => $p1, 'quantity' => 3, 'unitPriceCents' => 100],
                ['product' => $p1, 'quantity' => 0, 'unitPriceCents' => 100],
            ]])->assertStatus(422);
            $this->client->patch("/vector/r/orders/{$id}", body: ['lines' => [['product' => $p1, 'quantity' => 0, 'unitPriceCents' => 100]]], headers: $this->realAdminHeaders())
                ->assertValidationError('lines', 0, 'quantity');
            self::assertSame(1, $this->rows($id)[0]['quantity'], 'nothing was written');

            $this->client->patch("/vector/r/orders/{$id}", body: ['lines' => [['id' => 999_999_999, 'product' => $p1, 'quantity' => 1, 'unitPriceCents' => 1]]], headers: $this->realAdminHeaders())
                ->assertValidationError('lines', 0, 'id');
            $this->client->patch("/vector/r/orders/{$id}", body: ['lines' => [['product' => $p1, 'quantity' => 1, 'unitPriceCents' => 1, 'order' => $id]]], headers: $this->realAdminHeaders())
                ->assertValidationError('lines', 0, 'order');
            $orders->update($id, ['lines' => []])->assertInvalid('lines', 'count_out_of_range');
            $orders->update($id, ['lines' => 'x'])->assertInvalid('lines', 'type_mismatch');
            $orders->create($this->order(null))->assertInvalid('lines', 'required');

            // a repeater is written with its record, never in bulk
            $orders->batch('update', [$id], fields: ['lines' => []])->assertInvalid('lines');
        } finally {
            $orders->batch('delete', [$id])->assertOk();
        }
    }

    public function testEachRowIsTheChildPolicysDecision(): void
    {
        $orders = $this->vector($this->realAdminHeaders())->resource('orders');
        [$p1, $p2] = $this->productIds(2);
        $id = $orders->create($this->order([
            ['product' => $p1, 'quantity' => 1, 'unitPriceCents' => 100],
            ['product' => $p2, 'quantity' => 1, 'unitPriceCents' => 100],
        ]))->assertCreated()->id();
        $rows = $this->rows($id);
        $this->policy->allowLineDelete = false;

        try {
            $orders->update($id, ['lines' => [['id' => $rows[0]['id'], 'product' => $p1, 'quantity' => 1, 'unitPriceCents' => 100]]])->assertForbidden();
            self::assertCount(2, $this->rows($id));
        } finally {
            $this->policy->allowLineDelete = true;
            $orders->batch('delete', [$id])->assertOk();
        }
    }

    public function testARepeaterNeedsAnOwnedRelationAndEveryRequiredField(): void
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));
        // a customer's orders are not owned: an order outlives a change to its customer
        $notOwned = new #[Resource(Customer::class, slug: 'repeating-customers')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), HasMany::to('orders', PlainOrders::class)->repeater()];
            }
        };
        // a new line needs its unit price, which this repeater leaves out
        $missing = new #[Resource(Order::class, slug: 'repeating-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), HasMany::to('lines', PlainOrderLines::class)->repeater(['quantity'])];
            }
        };

        foreach ([
            [$notOwned, 'needs an owned #[HasMany]', PlainOrders::class],
            [$missing, 'leaves out "unitPriceCents"', PlainOrderLines::class],
        ] as [$resource, $message, $child]) {
            try {
                (new ResourceCompiler($metadata, [$resource::class, $child]))->compile();
                self::fail("compiled: {$message}");
            } catch (ResourceCompileException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    /**
     * @param list<array<string, mixed>>|null $lines
     * @return array<string, mixed>
     */
    private function order(?array $lines): array
    {
        $order = [
            'number' => 'ORD-REP-' . bin2hex(random_bytes(3)),
            'customer' => (int) $this->shop()->table('customers')->select('id')->orderBy('id', 'ASC')->value('id'),
            'status' => 'pending',
            'currency' => 'EUR',
            'placedAt' => '2026-09-26T09:00:00Z',
        ];

        return $lines === null ? $order : [...$order, 'lines' => $lines];
    }

    /** @return list<array<string, mixed>> */
    private function rows(int|string $orderId): array
    {
        $rows = $this->client->get("/vector/r/orders/{$orderId}/lines/rows", headers: $this->realAdminHeaders())->assertOk()->json();
        self::assertIsArray($rows);

        return $rows['data'];
    }

    /** @return list<int> */
    private function productIds(int $count): array
    {
        return array_map('intval', array_column($this->shop()->table('products')->select('id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->limit($count)->get(), 'id'));
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}

/** Allows everything, except deleting an order line when the test switches it off. */
final class RepeaterPolicy extends ResourcePolicy
{
    public bool $allowLineDelete = true;

    public function delete(CurrentUserInterface $user, array $record): bool
    {
        return $this->allowLineDelete || !array_key_exists('unitPriceCents', $record);
    }
}

/** Orders with every field derived, for a customer's repeater to point at. */
#[Resource(Order::class, slug: 'plain-orders')]
final class PlainOrders extends VectorResource {}

/** Order lines with every field derived. */
#[Resource(OrderLine::class, slug: 'plain-order-lines')]
final class PlainOrderLines extends VectorResource {}
