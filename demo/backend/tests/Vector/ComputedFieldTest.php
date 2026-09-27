<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\OrderLine;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\Computed;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\VectorResource;

/**
 * A read-only value a resource method computes from the serialized
 * record: the demo's order line total. No statement of its own; its
 * sources travel with it; hidden with any field it reads.
 */
final class ComputedFieldTest extends VectorTestCase
{
    private ComputedHidingPolicy $policy;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->policy = new ComputedHidingPolicy();
        $app->instance(AllowAllPolicy::class, $this->policy);
    }

    public function testTheListAndTheRecordCarryTheComputedValue(): void
    {
        $page = $this->client->get('/vector/r/order-lines', query: ['perPage' => 20], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($page);

        foreach ($page['data'] as $line) {
            self::assertSame($line['quantity'] * $line['unitPriceCents'], $line['lineTotal']);
        }

        $first = $page['data'][0];
        $this->client->get("/vector/r/order-lines/{$first['id']}", headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.lineTotal', $first['lineTotal']);
    }

    public function testTheDescriptorPublishesWhatItIsShownAsAndNotItsMethod(): void
    {
        $schema = $this->client->get('/vector/schema/order-lines', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($schema);
        $field = array_values(array_filter($schema['fields'], static fn (array $f): bool => $f['name'] === 'lineTotal'))[0];

        self::assertSame('computed', $field['type']);
        self::assertSame(['as' => 'money', 'from' => ['quantity', 'unitPriceCents']], $field['computed']);
        self::assertSame(['index', 'show'], $field['views']);
        self::assertSame([true, false, false, false, []], [$field['readonly'], $field['sortable'], $field['filterable'], $field['searchable'], $field['operators']]);
    }

    public function testAColumnListNamingItBringsItsSourcesAlong(): void
    {
        $page = $this->client->get('/vector/r/order-lines', query: ['perPage' => 1, 'columns' => 'lineTotal'], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($page);

        self::assertSame(['id', 'lineTotal', 'quantity', 'unitPriceCents', 'order'], array_keys($page['data'][0]));
        self::assertSame($page['data'][0]['quantity'] * $page['data'][0]['unitPriceCents'], $page['data'][0]['lineTotal']);
        self::assertSame('EUR', $page['data'][0]['order']['currency'], 'the currency it is priced in travels with it');
    }

    public function testItCostsNoStatement(): void
    {
        $query = static fn (string $columns): array => ['perPage' => 25, 'columns' => $columns];
        $without = $this->countStatements(fn () => $this->client->get('/vector/r/order-lines', query: $query('quantity,unitPriceCents,order'), headers: $this->adminHeaders())->assertOk());
        $with = $this->countStatements(fn () => $this->client->get('/vector/r/order-lines', query: $query('quantity,unitPriceCents,order,lineTotal'), headers: $this->adminHeaders())->assertOk());

        self::assertSame($without, $with);
    }

    public function testTheExportCarriesIt(): void
    {
        $csv = $this->client->get('/vector/r/order-lines/export', query: ['columns' => 'quantity,unitPriceCents,lineTotal', 'filter' => ['order' => ['eq' => '1']]], headers: $this->adminHeaders())
            ->assertOk()->body();
        $lines = array_map(static fn (string $line): array => str_getcsv($line, escape: ''), explode("\r\n", trim($csv)));

        self::assertSame("\xEF\xBB\xBF" . 'Quantity', $lines[0][0]);
        self::assertSame(['Unit price', 'Total'], [$lines[0][1], $lines[0][2]]);
        self::assertGreaterThan(1, count($lines));

        foreach (array_slice($lines, 1) as [$quantity, $unit, $total]) {
            self::assertSame((int) $quantity * (int) $unit, (int) $total);
        }
    }

    public function testItCannotBeSortedOrFiltered(): void
    {
        $this->client->get('/vector/r/order-lines', query: ['sort' => 'lineTotal'], headers: $this->adminHeaders())->assertStatus(422);
        $this->client->get('/vector/r/order-lines', query: ['filter' => ['lineTotal' => ['gt' => '0']]], headers: $this->adminHeaders())->assertStatus(422);
    }

    public function testHidingAFieldItReadsHidesIt(): void
    {
        $this->policy->hidden = ['unitPriceCents'];

        $schema = $this->client->get('/vector/schema/order-lines', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($schema);
        self::assertNotContains('lineTotal', array_column($schema['fields'], 'name'));

        $page = $this->client->get('/vector/r/order-lines', query: ['perPage' => 1], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($page);
        self::assertArrayNotHasKey('lineTotal', $page['data'][0]);
        self::assertArrayNotHasKey('unitPriceCents', $page['data'][0]);

        $this->client->get('/vector/r/order-lines', query: ['columns' => 'lineTotal'], headers: $this->adminHeaders())->assertStatus(422);
    }

    public function testItNeedsAPublicMethodOfTheResource(): void
    {
        $this->expectCompileError('is computed by missing(), which is not a public instance method of the resource', new #[Resource(OrderLine::class, slug: 'computed-lines')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Computed::make('total')->method('missing')];
            }
        });
    }

    public function testItNeedsAMethodAtAll(): void
    {
        $this->expectCompileError('is computed but names no method', new #[Resource(OrderLine::class, slug: 'computed-lines')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Computed::make('total')];
            }
        });
    }

    public function testItIsNeverOnAForm(): void
    {
        $this->expectCompileError('is computed, so it cannot be on a form', new #[Resource(OrderLine::class, slug: 'computed-lines')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Computed::make('total')->method('total')->onlyOnForms()];
            }

            /** @param array<string, mixed> $row */
            public function total(array $row): int
            {
                return 0;
            }
        });
    }

    public function testItIsNeverSorted(): void
    {
        $this->expectCompileError('is computed per record in PHP, so it cannot be sortable', new #[Resource(OrderLine::class, slug: 'computed-lines')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Computed::make('total')->method('total')->sortable()];
            }

            /** @param array<string, mixed> $row */
            public function total(array $row): int
            {
                return 0;
            }
        });
    }

    public function testItIsNeverSummarized(): void
    {
        $this->expectCompileError('is computed per record in PHP; a summary needs a column the database can aggregate', new #[Resource(OrderLine::class, slug: 'computed-lines')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Computed::make('total')->as('number')->method('total')->summarize('sum')];
            }

            /** @param array<string, mixed> $row */
            public function total(array $row): int
            {
                return 0;
            }
        });
    }

    public function testAnEnumNeedsOptions(): void
    {
        $this->expectCompileError('is computed as an enum but lists no options', new #[Resource(OrderLine::class, slug: 'computed-lines')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Computed::make('size')->as('enum')->method('size')];
            }

            /** @param array<string, mixed> $row */
            public function size(array $row): string
            {
                return 'small';
            }
        });
    }

    public function testItReadsOnlyFieldsHoldingAValue(): void
    {
        $this->expectCompileError('is computed from "nothing", which is not a field of the resource holding a value of its own', new #[Resource(OrderLine::class, slug: 'computed-lines')] class extends VectorResource {
            public function fields(): array
            {
                return [
                    Id::make(),
                    Number::make('quantity'),
                    Computed::make('double')->as('number')->from(['quantity', 'nothing'])->method('double'),
                ];
            }

            /** @param array<string, mixed> $row */
            public function double(array $row): int
            {
                return 2 * (int) $row['quantity'];
            }
        });
    }

    public function testAsIsOneOfTheKnownKinds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Computed::make('total')->as('html');
    }

    private function expectCompileError(string $message, VectorResource $resource): void
    {
        $this->expectException(ResourceCompileException::class);
        $this->expectExceptionMessage($message);
        self::compile($resource);
    }

    private static function compile(VectorResource $resource): void
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));
        (new ResourceCompiler($metadata, [$resource::class]))->compile();
    }
}

final class ComputedHidingPolicy extends ResourcePolicy
{
    /** @var list<string> */
    public array $hidden = [];

    public function viewField(CurrentUserInterface $user, string $field): bool
    {
        return !in_array($field, $this->hidden, true);
    }
}
