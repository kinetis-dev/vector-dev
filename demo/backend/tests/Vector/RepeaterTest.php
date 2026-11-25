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
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Fields\Repeater;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Store\AuditStore;
use Kinetis\Vector\Testing\InteractsWithVector;
use Kinetis\Vector\VectorResource;

/**
 * A relationship-mode repeater writes an owned relation's rows with their
 * parent: the whole list in one transaction, each row validated,
 * authorized, hooked and audited as its child resource's own create,
 * update or delete, in the order the rows were sent.
 */
final class RepeaterTest extends VectorTestCase
{
    use InteractsWithVector;

    /** A unit price no seeded line has: the lines a test's unique index covers. */
    private const int UNIQUE_PRICE = 777_777;

    /** A unit price no seeded line has: the lines ScopedOrderLines hides. */
    private const int HIDDEN_PRICE = 333_333;

    /** The tests whose order lines are ScopedOrderLines, which hide the HIDDEN_PRICE lines. */
    private const array SCOPED = ['testAChildTheScopeHidesIsLeftAloneBySaving', 'testARowNamingAHiddenChildIsRefusedAsAStrangersRow'];

    private RepeaterPolicy $policy;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->policy = new RepeaterPolicy();
        $app->instance(AllowAllPolicy::class, $this->policy);

        if (in_array($this->name(), self::SCOPED, true)) {
            $registry = $app->get(VectorRegistry::class);
            self::assertInstanceOf(VectorRegistry::class, $registry);
            $data = $registry->toArray();
            $data['resources']['order-lines']['class'] = ScopedOrderLines::class;
            $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));
        }
    }

    public function testTheSchemaDescribesTheRowsAForm(): void
    {
        $lines = array_column($this->vector($this->adminHeaders())->schema('orders')->assertOk()->json()['fields'], null, 'name')['lines'];
        self::assertSame('repeater', $lines['type']);
        self::assertSame(['fields' => ['product', 'quantity', 'unitPriceCents'], 'mode' => 'relationship', 'min' => 1, 'max' => 50, 'sortable' => true, 'orderBy' => 'position'], $lines['repeater']);
        self::assertSame(['kind' => 'hasMany', 'resource' => 'order-lines', 'titleField' => null, 'inverse' => 'order', 'owned' => true], $lines['relation'], 'the child table, its columns and the position column stay on the server');
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
            self::assertSame(['count' => 2], $orders->show($id)->json()['data']['lines'], 'a record shows its rows as a count');
        } finally {
            $orders->batch('delete', [$id])->assertOk();
        }
    }

    public function testTheRowsTakeTheOrderTheyAreSentIn(): void
    {
        $orders = $this->vector($this->realAdminHeaders())->resource('orders');
        [$p1, $p2, $p3] = $this->productIds(3);
        $id = $orders->create($this->order([
            ['product' => $p1, 'quantity' => 1, 'unitPriceCents' => 100],
            ['product' => $p2, 'quantity' => 2, 'unitPriceCents' => 100],
            ['product' => $p3, 'quantity' => 3, 'unitPriceCents' => 100],
        ]))->assertCreated()->id();

        try {
            $rows = $this->rows($id);
            self::assertSame([[$p1, 0], [$p2, 1], [$p3, 2]], $this->positions($id), 'each row is written with its index');

            // dragged: the third line first, then the first; the second is dropped and a new one ends the list
            $orders->update($id, ['lines' => [
                ['id' => $rows[2]['id'], 'product' => $p3, 'quantity' => 3, 'unitPriceCents' => 100],
                ['id' => $rows[0]['id'], 'product' => $p1, 'quantity' => 1, 'unitPriceCents' => 100],
                ['product' => $p2, 'quantity' => 9, 'unitPriceCents' => 100],
            ]])->assertOk();

            self::assertSame([[$p3, 0], [$p1, 1], [$p2, 2]], $this->positions($id));
            $after = $this->rows($id);
            self::assertSame([$rows[2]['id'], $rows[0]['id']], [$after[0]['id'], $after[1]['id']], 'the rows load in the order they were saved in, not by id');
            self::assertSame(9, $after[2]['quantity']);
        } finally {
            $orders->batch('delete', [$id])->assertOk();
        }
    }

    public function testAFailingRowRollsBackTheRecordAndEveryOtherRow(): void
    {
        $orders = $this->vector($this->realAdminHeaders())->resource('orders');
        [$p1, $p2] = $this->productIds(2);
        $id = $orders->create($this->order([
            ['product' => $p1, 'quantity' => 1, 'unitPriceCents' => self::UNIQUE_PRICE],
            ['product' => $p2, 'quantity' => 2, 'unitPriceCents' => self::UNIQUE_PRICE],
        ]))->assertCreated()->id();
        $rows = $this->rows($id);
        // only lines at this price take part: two of them in one order may not share a quantity
        $this->link()->execute('CREATE UNIQUE INDEX order_lines_quantity_repeater_test ON order_lines ((CASE WHEN unit_price_cents = ' . self::UNIQUE_PRICE . ' THEN order_id END), (CASE WHEN unit_price_cents = ' . self::UNIQUE_PRICE . ' THEN quantity END))');

        try {
            // the record's own change, an update, a new row and a removal: the database refuses the new row at flush
            $this->client->patch("/vector/r/orders/{$id}", body: ['notes' => 'Changed', 'lines' => [
                ['id' => $rows[1]['id'], 'product' => $p2, 'quantity' => 5, 'unitPriceCents' => self::UNIQUE_PRICE],
                ['product' => $p1, 'quantity' => 5, 'unitPriceCents' => self::UNIQUE_PRICE],
            ]], headers: $this->realAdminHeaders())->assertValidationError('lines', 1, 'quantity');

            self::assertNull($orders->show($id)->json()['data']['notes'], 'the record is unchanged');
            self::assertSame([[$p1, 1], [$p2, 2]], array_map(static fn (array $row): array => [$row['product']['id'], $row['quantity']], $this->rows($id)), 'every row is as it was: none updated, created or deleted');
        } finally {
            $this->link()->execute('DROP INDEX order_lines_quantity_repeater_test ON order_lines');
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
            $this->client->patch("/vector/r/orders/{$id}", body: ['lines' => [
                ['product' => $p1, 'quantity' => 1, 'unitPriceCents' => 100],
                ['product' => $p1, 'quantity' => 1, 'unitPriceCents' => -5],
                ['product' => $p1, 'quantity' => 0, 'unitPriceCents' => 100],
            ]], headers: $this->realAdminHeaders())
                ->assertValidationError('lines', 1, 'unitPriceCents')
                ->assertValidationError('lines', 2, 'quantity');
            self::assertSame(1, $this->rows($id)[0]['quantity'], 'nothing was written');

            $this->client->patch("/vector/r/orders/{$id}", body: ['lines' => [['id' => 999_999_999, 'product' => $p1, 'quantity' => 1, 'unitPriceCents' => 1]]], headers: $this->realAdminHeaders())
                ->assertValidationError('lines', 0, 'id');
            $this->client->patch("/vector/r/orders/{$id}", body: ['lines' => [['product' => $p1, 'quantity' => 1, 'unitPriceCents' => 1, 'order' => $id]]], headers: $this->realAdminHeaders())
                ->assertValidationError('lines', 0, 'order');

            // another order's line is no row of this one: refused, and neither order changes
            $other = $orders->create($this->order([['product' => $p1, 'quantity' => 4, 'unitPriceCents' => 100]]))->assertCreated()->id();

            try {
                $theirs = $this->rows($other)[0]['id'];
                $this->client->patch("/vector/r/orders/{$id}", body: ['lines' => [['id' => $theirs, 'product' => $p1, 'quantity' => 9, 'unitPriceCents' => 100]]], headers: $this->realAdminHeaders())
                    ->assertValidationError('lines', 0, 'id');
                self::assertSame([[$line, 1]], array_map(static fn (array $row): array => [$row['id'], $row['quantity']], $this->rows($id)), 'this order keeps its own line');
                self::assertSame([[$theirs, 4]], array_map(static fn (array $row): array => [$row['id'], $row['quantity']], $this->rows($other)), 'the other order keeps its line, unchanged');
            } finally {
                $orders->batch('delete', [$other])->assertOk();
            }
            $orders->update($id, ['lines' => []])->assertInvalid('lines', 'count_out_of_range');
            $orders->update($id, ['lines' => 'x'])->assertInvalid('lines', 'type_mismatch');
            $orders->update($id, ['lines' => array_fill(0, 51, ['product' => $p1, 'quantity' => 1, 'unitPriceCents' => 1])])->assertInvalid('lines', 'count_out_of_range');
            $orders->create($this->order(null))->assertInvalid('lines', 'required');

            // a repeater is written with its record, never in bulk
            $orders->batch('update', [$id], fields: ['lines' => []])->assertInvalid('lines');
        } finally {
            $orders->batch('delete', [$id])->assertOk();
        }
    }

    /**
     * A parent holding more children than max() (stored before the limit,
     * or written around Vector) loads every one into the form, and a save
     * of that list is refused rather than deleting the ones past max().
     */
    public function testChildrenPastMaxAreLoadedAndNeverDeleted(): void
    {
        $orders = $this->vector($this->realAdminHeaders())->resource('orders');
        [$p1] = $this->productIds(1);
        $id = $orders->create($this->order(array_fill(0, 50, ['product' => $p1, 'quantity' => 1, 'unitPriceCents' => 1])))->assertCreated()->id();

        try {
            foreach ([50, 51] as $position) {
                $this->shop()->table('order_lines')->insert(['order_id' => $id, 'product_id' => $p1, 'quantity' => 2, 'unit_price_cents' => 1, 'position' => $position]);
            }

            $rows = $this->rows($id);
            self::assertCount(52, $rows, 'the form gets every child, not the first max() of them');

            $orders->update($id, ['lines' => array_map(static fn (array $row): array => ['id' => $row['id'], 'product' => $p1, 'quantity' => $row['quantity'], 'unitPriceCents' => 1], $rows)])
                ->assertInvalid('lines', 'count_out_of_range');
            self::assertSame(52, $this->shop()->table('order_lines')->where('order_id', '=', $id)->count(), 'no child was deleted');

            $orders->update($id, ['notes' => 'Past max'])->assertOk();
            self::assertSame(52, $this->shop()->table('order_lines')->where('order_id', '=', $id)->count(), 'an update leaving the rows alone keeps them');
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

    /**
     * A line the order lines' scope hides never reaches the form, so a
     * save that leaves it out has not removed it: it stays as it was, in
     * its own position, and nothing records a delete of it.
     */
    public function testAChildTheScopeHidesIsLeftAloneBySaving(): void
    {
        $orders = $this->vector($this->realAdminHeaders())->resource('orders');
        [$p1, $p2, $p3] = $this->productIds(3);
        $id = $orders->create($this->order([
            ['product' => $p1, 'quantity' => 1, 'unitPriceCents' => 100],
            ['product' => $p2, 'quantity' => 2, 'unitPriceCents' => 100],
        ]))->assertCreated()->id();

        try {
            $hidden = $this->hiddenLine($id, $p3);
            $before = $this->line($hidden);
            $rows = $this->rows($id);
            self::assertSame([$p1, $p2], array_map(static fn (array $row): int => $row['product']['id'], $rows), 'the form gets the lines the scope shows');

            $orders->update($id, ['lines' => [
                ['id' => $rows[1]['id'], 'product' => $p2, 'quantity' => 7, 'unitPriceCents' => 100],
                ['id' => $rows[0]['id'], 'product' => $p1, 'quantity' => 1, 'unitPriceCents' => 100],
            ]])->assertOk();

            self::assertSame($before, $this->line($hidden), 'the hidden line is unchanged, its position included');
            self::assertSame([[$p2, 7, 0], [$p1, 1, 1]], array_map(fn (array $row): array => [$row['product']['id'], $row['quantity'], (int) $this->line((int) $row['id'])['position']], $this->rows($id)), 'the lines sent are written in their order');
            $audit = $this->app->get(AuditStore::class);
            self::assertInstanceOf(AuditStore::class, $audit);
            self::assertNotContains('delete', array_column($audit->trail('order-lines', (string) $hidden), 'event'), 'no delete is recorded for it');

            // a save that leaves every line out removes the ones it shows, and still not the hidden one
            $orders->update($id, ['lines' => [['product' => $p1, 'quantity' => 3, 'unitPriceCents' => 100]]])->assertOk();
            self::assertSame($before, $this->line($hidden));
            self::assertSame(2, $this->shop()->table('order_lines')->where('order_id', '=', $id)->count());
        } finally {
            $this->shop()->table('order_lines')->where('order_id', '=', $id)->where('unit_price_cents', '=', self::HIDDEN_PRICE)->delete();
            $orders->batch('delete', [$id])->assertOk();
        }
    }

    /**
     * A row that names a hidden line by its id is refused exactly as one
     * naming another order's line is, so the answer says nothing about
     * whether the hidden line exists, and the line is not touched.
     */
    public function testARowNamingAHiddenChildIsRefusedAsAStrangersRow(): void
    {
        $orders = $this->vector($this->realAdminHeaders())->resource('orders');
        [$p1, $p2] = $this->productIds(2);
        $id = $orders->create($this->order([['product' => $p1, 'quantity' => 1, 'unitPriceCents' => 100]]))->assertCreated()->id();
        $other = $orders->create($this->order([['product' => $p1, 'quantity' => 1, 'unitPriceCents' => 100]]))->assertCreated()->id();

        try {
            $hidden = $this->hiddenLine($id, $p2);
            $before = $this->line($hidden);
            $stranger = (int) $this->rows($other)[0]['id'];
            $errors = [];

            foreach (['hidden' => $hidden, 'stranger' => $stranger] as $case => $lineId) {
                $answer = $orders->update($id, ['lines' => [['id' => $lineId, 'product' => $p2, 'quantity' => 9, 'unitPriceCents' => 100]]])->assertStatus(422)->json();
                $errors[$case] = array_values(array_filter($answer['errors'], static fn (array $e): bool => $e['path'] === ['lines', 0, 'id']));
            }

            self::assertSame('unknown_row', $errors['hidden'][0]['code'] ?? null);
            self::assertSame($errors['stranger'], $errors['hidden'], 'the same answer as for a line of another order');
            self::assertSame($before, $this->line($hidden), 'the hidden line is unchanged');
            self::assertSame(2, $this->shop()->table('order_lines')->where('order_id', '=', $id)->count(), 'nothing was created or removed');
        } finally {
            $this->shop()->table('order_lines')->where('order_id', '=', $id)->where('unit_price_cents', '=', self::HIDDEN_PRICE)->delete();
            $orders->batch('delete', [$id, $other])->assertOk();
        }
    }

    public function testARepeaterNeedsAnOwnedRelationAndEveryRequiredField(): void
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));
        // a customer's orders are not owned: an order outlives a change to its customer
        $notOwned = new #[Resource(Customer::class, slug: 'repeating-customers')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('orders')->relationship('orders', PlainOrders::class)];
            }
        };
        // a new line needs its unit price, which this repeater leaves out
        $missing = new #[Resource(Order::class, slug: 'repeating-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('lines')->relationship('lines', PlainOrderLines::class)->fields(['quantity'])];
            }
        };
        $notARelation = new #[Resource(Order::class, slug: 'repeating-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('lines')->relationship('items', PlainOrderLines::class)];
            }
        };
        // two resources over orders: this one is the one relations link to
        $wrongChild = new #[Resource(Order::class, slug: 'repeating-orders', relationTarget: true)] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('lines')->relationship('lines', PlainOrders::class)];
            }
        };
        $badOrder = new #[Resource(Order::class, slug: 'repeating-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('lines')->relationship('lines', PlainOrderLines::class)->orderBy('quantityy')];
            }
        };
        $orderByRelation = new #[Resource(Order::class, slug: 'repeating-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('lines')->relationship('lines', PlainOrderLines::class)->orderBy('product')];
            }
        };
        $orderedRowField = new #[Resource(Order::class, slug: 'repeating-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('lines')->relationship('lines', PlainOrderLines::class)->fields(['quantity', 'unitPriceCents', 'position'])->orderBy('position')];
            }
        };
        $rowObjects = new #[Resource(Order::class, slug: 'repeating-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('lines')->relationship('lines', PlainOrderLines::class)->fields([Number::make('quantity')])];
            }
        };
        $sorted = new #[Resource(Order::class, slug: 'repeating-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('lines')->relationship('lines', PlainOrderLines::class)->sortable()];
            }
        };
        $filtered = new #[Resource(Order::class, slug: 'repeating-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('lines')->relationship('lines', PlainOrderLines::class)->filterable()];
            }
        };
        $bounds = new #[Resource(Order::class, slug: 'repeating-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('lines')->relationship('lines', PlainOrderLines::class)->min(5)->max(2)];
            }
        };

        foreach ([
            [$notOwned, 'needs an owned #[HasMany]', PlainOrders::class],
            [$missing, 'leaves out "unitPriceCents"', PlainOrderLines::class],
            [$notARelation, 'names the relation "items", which is not a #[HasMany] property of App\\Domain\\Order', PlainOrderLines::class],
            [$wrongChild, 'names App\\Tests\\Vector\\PlainOrders, which administers App\\Domain\\Order, not App\\Domain\\OrderLine', [PlainOrders::class, PlainOrderLines::class]],
            [$badOrder, 'orders its rows by "quantityy", which is not an int property of App\\Domain\\OrderLine', PlainOrderLines::class],
            [$orderByRelation, 'orders its rows by "product", which is not an int property', PlainOrderLines::class],
            [$orderedRowField, 'names "position", the property orderBy() fills with each row\'s position', PlainOrderLines::class],
            [$rowObjects, 'declares the row field "quantity"; in relationship mode fields() names fields of the child resource', PlainOrderLines::class],
            [$sorted, 'maps no column and cannot be sortable', PlainOrderLines::class],
            [$filtered, 'is a collection and cannot be filterable', PlainOrderLines::class],
            [$bounds, 'min() (5) exceeds its max() (2)', PlainOrderLines::class],
        ] as [$resource, $message, $child]) {
            try {
                (new ResourceCompiler($metadata, [$resource::class, ...(array) $child]))->compile();
                self::fail("compiled: {$message}");
            } catch (ResourceCompileException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    /**
     * The ORM already refuses a relationship across connections; the
     * compiler refuses it again for a repeater, whose save is one
     * transaction, should metadata ever carry one.
     */
    public function testAChildOnAnotherConnectionIsRefused(): void
    {
        $entities = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class))->toArray()['entities'];

        // order lines as if they lived on the support database: the repeater could not save them with their order
        foreach ($entities as $i => $entity) {
            if ($entity['class'] === OrderLine::class) {
                $entities[$i]['connection'] = 'support';
            }
        }

        $metadata = new \ReflectionClass(MetadataRegistry::class)->newInstanceWithoutConstructor();
        (function () use ($entities): void {
            // the registry's own scope, which is where its constructor would have set it
            /** @phpstan-ignore property.readOnlyAssignOutOfClass */
            $this->entities = $entities;
        })->call($metadata);
        $orders = new #[Resource(Order::class, slug: 'repeating-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('lines')->relationship('lines', PlainOrderLines::class)];
            }
        };

        try {
            (new ResourceCompiler($metadata, [$orders::class, PlainOrderLines::class]))->compile();
            self::fail('compiled a repeater whose rows live on another connection');
        } catch (ResourceCompileException $e) {
            self::assertStringContainsString('edits App\\Domain\\OrderLine on the "support" connection, but App\\Domain\\Order lives on "default"; a repeater saves its rows with the parent in one transaction', $e->getMessage());
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

    /**
     * Each line of an order as stored, by position: its product and the position it holds.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function positions(int|string $orderId): array
    {
        $rows = $this->shop()->table('order_lines')->select('product_id', 'position')->where('order_id', '=', (int) $orderId)->orderBy('position', 'ASC')->get();

        return array_map(static fn (array $row): array => [(int) $row['product_id'], (int) $row['position']], $rows);
    }

    /** A line of the order that ScopedOrderLines hides, after the lines it has: its id. */
    private function hiddenLine(int|string $orderId, int $productId): int
    {
        $position = $this->shop()->table('order_lines')->where('order_id', '=', (int) $orderId)->count();
        $this->shop()->table('order_lines')->insert(['order_id' => (int) $orderId, 'product_id' => $productId, 'quantity' => 4, 'unit_price_cents' => self::HIDDEN_PRICE, 'position' => $position]);

        return (int) $this->shop()->table('order_lines')->select('id')->where('order_id', '=', (int) $orderId)->where('unit_price_cents', '=', self::HIDDEN_PRICE)->value('id');
    }

    /** @return array<string, mixed> one line as stored */
    private function line(int $lineId): array
    {
        $row = $this->shop()->table('order_lines')->select('order_id', 'product_id', 'quantity', 'unit_price_cents', 'position')->where('id', '=', $lineId)->first();
        self::assertIsArray($row, "line {$lineId} is gone");

        return $row;
    }

    /** @return list<int> */
    private function productIds(int $count): array
    {
        return array_map('intval', array_column($this->shop()->table('products')->select('id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->limit($count)->get(), 'id'));
    }

    private function link(): MysqlLink
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return $link;
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

/**
 * The order lines, as the demo's resource compiles them, with a scope that
 * hides the lines priced at RepeaterTest::HIDDEN_PRICE (333 333).
 */
final class ScopedOrderLines extends VectorResource
{
    #[\Override]
    public function fields(): array
    {
        return [];
    }

    #[\Override]
    public function scope(Query $query, CurrentUserInterface $user): Query
    {
        return $query->where('unit_price_cents', '<>', 333_333);
    }

    /**
     * The computed line total the compiled resource names.
     *
     * @param array<string, mixed> $line
     */
    public function lineTotal(array $line): ?int
    {
        return is_int($line['quantity'] ?? null) && is_int($line['unitPriceCents'] ?? null) ? $line['quantity'] * $line['unitPriceCents'] : null;
    }
}

/** Orders with every field derived, for a customer's repeater to point at. */
#[Resource(Order::class, slug: 'plain-orders')]
final class PlainOrders extends VectorResource {}

/** Order lines with every field derived. */
#[Resource(OrderLine::class, slug: 'plain-order-lines')]
final class PlainOrderLines extends VectorResource {}

