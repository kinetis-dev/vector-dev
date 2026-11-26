<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Product;
use Kinetis\Orm\Date as Day;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\Date;
use Kinetis\Vector\Fields\DateRange;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Mcp\ResourceDescription;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Testing\InteractsWithVector;
use Kinetis\Vector\Validation\DateBounds;
use Kinetis\Vector\VectorResource;

/**
 * DateRange: one value, `{from, to}`, kept in two properties of the
 * entity, both days or both instants. It is written to both ends and
 * read back from both, refused when one-sided or reversed, imported and
 * exported as `from/to`. The demo's products have a promotion period.
 */
final class DateRangeFieldTest extends VectorTestCase
{
    use InteractsWithVector;
    use ValidatesInput;

    public function testTheBuildChecksBothEnds(): void
    {
        $cases = [
            'a day and an instant' => [new #[Resource(Product::class, slug: 'range-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), DateRange::make('window', from: 'promotionStartsOn', to: 'releasedAt')];
                }
            }, 'from "promotionStartsOn", a date, to "releasedAt", a timestamp; both ends are Kinetis\\Orm\\Date or both DateTimeImmutable'],
            'one end nullable' => [new #[Resource(Product::class, slug: 'range-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), DateRange::make('window', from: 'createdAt', to: 'releasedAt')];
                }
            }, 'of which only one is nullable'],
            'an end that is no date' => [new #[Resource(Product::class, slug: 'range-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), DateRange::make('window', from: 'promotionStartsOn', to: 'name')];
                }
            }, 'whose to is "name", which is not a Kinetis\\Orm\\Date or DateTimeImmutable property'],
            'an end that is no property' => [new #[Resource(Product::class, slug: 'range-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), DateRange::make('window', from: 'promotionStartsOn', to: 'nowhere')];
                }
            }, 'whose to is "nowhere"'],
            'the same property twice' => [new #[Resource(Product::class, slug: 'range-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), DateRange::make('window', from: 'promotionStartsOn', to: 'promotionStartsOn')];
                }
            }, 'from and to the same property "promotionStartsOn"'],
            'a name that is a property' => [new #[Resource(Product::class, slug: 'range-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), DateRange::make('promotionStartsOn', from: 'promotionStartsOn', to: 'promotionEndsOn')];
                }
            }, 'is a property itself'],
            'an end that is a field too' => [new #[Resource(Product::class, slug: 'range-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), DateRange::make('promotion', from: 'promotionStartsOn', to: 'promotionEndsOn'), Date::make('promotionEndsOn')];
                }
            }, 'maps "promotionEndsOn", which is also a field of the resource'],
            'an end two ranges write' => [new #[Resource(Product::class, slug: 'range-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), DateRange::make('promotion', from: 'promotionStartsOn', to: 'promotionEndsOn'), DateRange::make('again', from: 'promotionStartsOn', to: 'promotionEndsOn')];
                }
            }, 'maps "promotionStartsOn", which the field "again" writes too'],
            'a condition on it' => [new #[Resource(Product::class, slug: 'range-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), DateRange::make('promotion', from: 'promotionStartsOn', to: 'promotionEndsOn'), Text::make('slug')->visibleWhen('promotion', 'nnull')];
                }
            }, 'a visible condition on the date range "promotion"'],
            'a bound of the other kind' => [new #[Resource(Product::class, slug: 'range-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), DateRange::make('promotion', from: 'promotionStartsOn', to: 'promotionEndsOn')->maxDate('now')];
                }
            }, 'a date is bounded by a day'],
        ];

        foreach ($cases as $case => [$resource, $message]) {
            try {
                $this->compiled($resource, 'range-products');
                self::fail("compiled: {$case}");
            } catch (ResourceCompileException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $case);
            }
        }

        try {
            ResourceCompiler::compileLoose(DateRange::make('window', from: 'a', to: 'b'), [], 'an action');
            self::fail('an action asked for a date range');
        } catch (ResourceCompileException $e) {
            self::assertStringContainsString('is a dateRange field, which an action or settings group cannot ask for', $e->getMessage());
        }
    }

    public function testWhatARangeRefusesWhereItIsDeclared(): void
    {
        $refusals = [
            'no ends' => static fn () => DateRange::make('promotion'),
            'sortable' => static fn () => DateRange::make('promotion', from: 'a', to: 'b')->sortable(),
            'filterable' => static fn () => DateRange::make('promotion', from: 'a', to: 'b')->filterable(),
            'a summary' => static fn () => DateRange::make('promotion', from: 'a', to: 'b')->summarize('count'),
            'unique' => static fn () => DateRange::make('promotion', from: 'a', to: 'b')->unique(),
            'a length' => static fn () => DateRange::make('promotion', from: 'a', to: 'b')->max(10),
            'a placeholder' => static fn () => DateRange::make('promotion', from: 'a', to: 'b')->placeholder('When'),
        ];

        foreach ($refusals as $case => $declare) {
            try {
                $declare();
                self::fail("took {$case}");
            } catch (\LogicException $e) {
                self::assertStringStartsWith('"promotion": ', $e->getMessage(), $case);
            }
        }

        // turning them off is no refusal
        self::assertFalse(DateRange::make('promotion', from: 'a', to: 'b')->sortable(false)->filterable(false)->unique(false)->toArray()['sortable']);
    }

    public function testTheCompiledRangeKnowsItsKindAndColumns(): void
    {
        $days = $this->field('promotion');
        self::assertSame(['dateRange', 'date', true], [$days['type'], $days['valueType'], $days['nullable']]);
        self::assertSame(['kind' => 'date', 'from' => 'promotionStartsOn', 'to' => 'promotionEndsOn'], $days['range']);
        self::assertSame(['from' => 'promotion_starts_on', 'to' => 'promotion_ends_on'], $days['rangeColumns']);
        self::assertSame([], $days['operators'], 'never filtered as one value');
        self::assertContains(['constraint' => DateBounds::class, 'args' => ['date', '2020-01-01', null]], $days['rules'], 'minDate() bounds both ends');

        $published = array_column($this->vector($this->realAdminHeaders())->schema('products')->json()['fields'] ?? [], null, 'name')['promotion'] ?? self::fail('no published promotion');
        self::assertArrayNotHasKey('rangeColumns', $published, 'columns stay on the server');

        $instants = array_column($this->compiled(self::lifetime(), 'range-products')['fields'], null, 'name')['lifetime'];
        self::assertSame(['datetime', 'timestamp', false], [$instants['range']['kind'], $instants['valueType'], $instants['nullable']]);
    }

    public function testARangeIsWrittenToBothEndsAndReadBackAsOne(): void
    {
        $products = $this->vector($this->realAdminHeaders())->resource('products');
        [$id, $stored] = $this->product();

        try {
            $record = $products->update($id, ['promotion' => ['from' => '2026-12-01', 'to' => '2026-12-24']])->assertOk()->record();
            self::assertSame(['from' => '2026-12-01', 'to' => '2026-12-24'], $record['promotion']);
            self::assertSame(['2026-12-01', '2026-12-24'], $this->stored($id));
            self::assertSame(['from' => '2026-12-01', 'to' => '2026-12-24'], $products->show($id)->assertOk()->record()['promotion']);

            // one day is a range too
            self::assertSame(['from' => '2026-12-24', 'to' => '2026-12-24'], $products->update($id, ['promotion' => ['from' => '2026-12-24', 'to' => '2026-12-24']])->assertOk()->record()['promotion']);

            // no range, either way it is said
            self::assertNull($products->update($id, ['promotion' => ['from' => null, 'to' => '']])->assertOk()->record()['promotion']);
            self::assertSame([null, null], $this->stored($id));
            $products->update($id, ['promotion' => ['from' => '2027-01-01', 'to' => '2027-01-02']])->assertOk();
            self::assertNull($products->update($id, ['promotion' => null])->assertOk()->record()['promotion']);
            self::assertSame([null, null], $this->stored($id));

            // the audit trail records the range's change as one value
            $products->update($id, ['promotion' => ['from' => '2027-02-01', 'to' => '2027-02-10']])->assertOk();
            $entry = $products->audit($id)->assertOk()->json()['data'][0] ?? [];
            // in whatever key order the audit's JSON column keeps them
            self::assertEquals(['from' => null, 'to' => ['from' => '2027-02-01', 'to' => '2027-02-10']], $entry['changes']['promotion'] ?? null);

            // bulk edit writes both ends of every record
            $products->batch('update', [$id], fields: ['promotion' => ['from' => '2027-03-01', 'to' => '2027-03-31']])->assertOk();
            self::assertSame(['2027-03-01', '2027-03-31'], $this->stored($id));
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['promotion_starts_on' => $stored[0], 'promotion_ends_on' => $stored[1]]);
        }
    }

    public function testARevertPutsBothEndsBack(): void
    {
        $products = $this->vector($this->realAdminHeaders())->resource('products');
        [$id, $stored] = $this->product();

        try {
            $products->update($id, ['promotion' => ['from' => '2027-04-01', 'to' => '2027-04-30']])->assertOk();
            $products->update($id, ['promotion' => ['from' => '2027-05-10', 'to' => '2027-05-20']])->assertOk();
            $entry = $products->audit($id)->records()[0];
            self::assertSame('update', $entry['event']);

            // reverting the second change puts back the first range, both of its ends
            $reverted = $products->revert($id, $entry['id'])->assertOk()->json()['data'];
            self::assertSame(['from' => '2027-04-01', 'to' => '2027-04-30'], $reverted['promotion']);
            self::assertSame(['2027-04-01', '2027-04-30'], $this->stored($id));

            // and reverting a range set from none clears both ends
            $products->update($id, ['promotion' => null])->assertOk();
            $products->update($id, ['promotion' => ['from' => '2027-06-01', 'to' => '2027-06-02']])->assertOk();
            $products->revert($id, $products->audit($id)->records()[0]['id'])->assertOk();
            self::assertSame([null, null], $this->stored($id));
            self::assertSame('revert', $products->audit($id)->records()[0]['event']);
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['promotion_starts_on' => $stored[0], 'promotion_ends_on' => $stored[1]]);
        }
    }

    public function testARangeChangedSinceMakesTheRevertAConflict(): void
    {
        $products = $this->vector($this->realAdminHeaders())->resource('products');
        [$id, $stored] = $this->product();

        try {
            $products->update($id, ['promotion' => ['from' => '2027-07-01', 'to' => '2027-07-31']])->assertOk();
            $first = $products->audit($id)->records()[0]['id'];
            $products->update($id, ['promotion' => ['from' => '2027-08-01', 'to' => '2027-08-31']])->assertOk();

            // the range is not what the entry wrote any more: the revert is refused and writes nothing
            $products->revert($id, $first)->assertStatus(409);
            self::assertSame(['2027-08-01', '2027-08-31'], $this->stored($id));
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['promotion_starts_on' => $stored[0], 'promotion_ends_on' => $stored[1]]);
        }
    }

    public function testAOneSidedReversedOrMalformedRangeIsRefusedAtItsEnd(): void
    {
        $products = $this->vector($this->realAdminHeaders())->resource('products');
        [$id, $stored] = $this->product();

        $refusals = [
            [['from' => '2026-12-01', 'to' => null], ['promotion', 'to'], 'range_incomplete'],
            [['from' => '', 'to' => '2026-12-01'], ['promotion', 'from'], 'range_incomplete'],
            [['to' => '2026-12-01'], ['promotion', 'from'], 'range_incomplete'],
            [['from' => '2026-12-02', 'to' => '2026-12-01'], ['promotion', 'to'], 'range_reversed'],
            [['from' => '2026-12-01', 'to' => '2026-02-30'], ['promotion', 'to'], 'invalid_date'],
            [['from' => '2026-12-01T10:00:00Z', 'to' => '2026-12-02'], ['promotion', 'from'], 'type_mismatch'],
            [['from' => '2019-12-31', 'to' => '2020-01-05'], ['promotion', 'from'], 'date_too_early'],
            [['from' => '2026-12-01', 'to' => '2026-12-02', 'until' => '2026-12-03'], ['promotion', 'until'], 'unexpected_field'],
            [['2026-12-01', '2026-12-02'], ['promotion'], 'type_mismatch'],
            ['2026-12-01/2026-12-02', ['promotion'], 'type_mismatch'],
        ];

        foreach ($refusals as [$value, $path, $code]) {
            $errors = $products->update($id, ['promotion' => $value])->assertStatus(422)->json()['errors'];
            self::assertSame([[$path, $code]], array_map(static fn (array $e): array => [$e['path'], $e['code']], $errors), json_encode($value, JSON_THROW_ON_ERROR));
        }

        self::assertSame($stored, $this->stored($id), 'nothing was written');
        $bulk = $products->batch('update', [$id], fields: ['promotion' => ['from' => '2026-12-02', 'to' => '2026-12-01']])->assertStatus(422)->json()['errors'];
        self::assertSame([[['promotion', 'to'], 'range_reversed']], array_map(static fn (array $e): array => [$e['path'], $e['code']], $bulk), 'a bulk edit is refused the same way');
    }

    public function testRequiredAsksForBothEnds(): void
    {
        $compiled = $this->compiled(new #[Resource(Product::class, slug: 'range-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('sku'), Text::make('name'), DateRange::make('promotion', from: 'promotionStartsOn', to: 'promotionEndsOn')->required()];
            }
        }, 'range-products');
        $promotion = array_column($compiled['fields'], null, 'name')['promotion'];

        self::assertTrue(\Kinetis\Vector\Write\RecordInput::requiredOnCreate($promotion));
        self::assertSame([[['promotion'], 'not_blank']], $this->recordInput($compiled, ['promotion' => ['from' => null, 'to' => null]], true));
        self::assertSame([[['promotion'], 'not_blank']], $this->recordInput($compiled, ['promotion' => null], true));
        self::assertSame([[['promotion', 'from'], 'range_incomplete']], $this->recordInput($compiled, ['promotion' => ['from' => null, 'to' => '2026-01-01']], true));

        $values = $this->recordInput($compiled, ['promotion' => ['from' => '2026-01-01', 'to' => '2026-01-31']], true);
        self::assertEquals(['promotion' => ['from' => Day::fromString('2026-01-01'), 'to' => Day::fromString('2026-01-31')], 'promotionStartsOn' => Day::fromString('2026-01-01'), 'promotionEndsOn' => Day::fromString('2026-01-31')], $values, 'the range and each end, for the writer');
    }

    public function testARangeOfInstantsComparesInstants(): void
    {
        $compiled = $this->compiled(self::lifetime(), 'range-products');

        // the same instant in two zones is no reversal; a second earlier is
        $values = $this->recordInput($compiled, ['lifetime' => ['from' => '2026-01-01T10:00:00Z', 'to' => '2026-01-01T11:00:00+01:00']], true);
        self::assertEquals(new \DateTimeImmutable('2026-01-01T10:00:00Z'), $values['updatedAt'] ?? null);
        self::assertSame([[['lifetime', 'to'], 'range_reversed']], $this->recordInput($compiled, ['lifetime' => ['from' => '2026-01-01T10:00:00Z', 'to' => '2026-01-01T09:59:59Z']], true));
        self::assertSame([[['lifetime'], 'null_not_allowed']], $this->recordInput($compiled, ['lifetime' => ['from' => null, 'to' => null]], true), 'neither end may be empty');
    }

    public function testARangeIsExportedAndImportedAsFromSlashTo(): void
    {
        [$id, $stored] = $this->product();
        $sku = (string) $this->shop()->table('products')->where('id', '=', $id)->value('sku');

        try {
            $this->shop()->table('products')->where('id', '=', $id)->update(['promotion_starts_on' => '2026-05-01', 'promotion_ends_on' => '2026-05-15']);
            $csv = $this->client->get('/vector/r/products/export', query: ['columns' => 'sku,promotion', 'filter' => ['sku' => ['eq' => $sku]]], headers: $this->realAdminHeaders())->assertOk()->body();
            self::assertStringContainsString("{$sku},2026-05-01/2026-05-15", $csv);

            // the same file, edited, imported back
            $import = "SKU,Promotion\r\n{$sku},2026-06-01/2026-06-30\r\n";
            $this->assertImported($import, ['updated' => 1, 'failed' => 0]);
            self::assertSame(['2026-06-01', '2026-06-30'], $this->stored($id));

            // a one-sided cell fails its row
            $this->assertImported("SKU,Promotion\r\n{$sku},2026-07-01/\r\n", ['updated' => 0, 'failed' => 1]);
            self::assertSame(['2026-06-01', '2026-06-30'], $this->stored($id));
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['promotion_starts_on' => $stored[0], 'promotion_ends_on' => $stored[1]]);
        }
    }

    public function testOpenApiAndMcpDescribeTheValue(): void
    {
        $schemas = $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())->assertOk()->json()['components']['schemas'];
        $range = $schemas['products.Update']['properties']['promotion'];

        self::assertSame(['object', 'null'], $range['type']);
        self::assertSame(['from' => ['type' => 'string', 'format' => 'date'], 'to' => ['type' => 'string', 'format' => 'date']], $range['properties']);
        self::assertSame(['from', 'to'], $range['required']);

        $registry = $this->app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $described = array_column(ResourceDescription::of($registry->resource('products'), [])['fields'], null, 'name')['promotion'];
        self::assertStringStartsWith('an object {"from": "YYYY-MM-DD", "to": "YYYY-MM-DD"}', $described['format']);
        self::assertSame(['DateBounds("date", "2020-01-01", null)'], $described['rules']);
    }

    /** Products with their two timestamps as one range of instants. */
    private static function lifetime(): VectorResource
    {
        return new #[Resource(Product::class, slug: 'range-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('sku'), DateRange::make('lifetime', from: 'createdAt', to: 'updatedAt')];
            }
        };
    }

    /** @return array<string, mixed> */
    private function field(string $name): array
    {
        $registry = $this->app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);

        return $registry->resource('products')->field($name) ?? self::fail("products have no {$name}");
    }

    /** @param array<string, int> $expected */
    private function assertImported(string $csv, array $expected): void
    {
        $mapping = json_encode(['SKU' => 'sku', 'Promotion' => 'promotion'], JSON_THROW_ON_ERROR);
        $body = "--vector\r\nContent-Disposition: form-data; name=\"mapping\"\r\n\r\n{$mapping}\r\n"
            . "--vector\r\nContent-Disposition: form-data; name=\"mode\"\r\n\r\nupdate\r\n"
            . "--vector\r\nContent-Disposition: form-data; name=\"key\"\r\n\r\nsku\r\n"
            . "--vector\r\nContent-Disposition: form-data; name=\"file\"; filename=\"products.csv\"\r\nContent-Type: text/csv\r\n\r\n{$csv}\r\n--vector--\r\n";
        $queued = $this->client->raw('POST', '/vector/r/products/import', $body, ['Content-Type' => 'multipart/form-data; boundary=vector', ...$this->realAdminHeaders()])->assertStatus(202)->json();
        self::assertIsArray($queued);
        $result = $this->client->get("/vector/jobs/{$queued['jobId']}", headers: $this->realAdminHeaders())->assertOk()->json()['data']['result'] ?? null;
        self::assertIsArray($result);

        // the job's counts, whatever order its JSON column keeps them in
        self::assertEquals($expected, array_intersect_key($result, $expected));
    }

    /** @return array{0: int, 1: array{0: string|null, 1: string|null}} a product's id and its stored promotion */
    private function product(): array
    {
        $row = $this->shop()->table('products')->select('id')->where('status', '!=', 'archived')->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);
        $id = (int) $row['id'];

        return [$id, $this->stored($id)];
    }

    /** @return array{0: string|null, 1: string|null} */
    private function stored(int $id): array
    {
        $row = $this->shop()->table('products')->select('promotion_starts_on', 'promotion_ends_on')->where('id', '=', $id)->first();
        self::assertIsArray($row);

        $day = static fn (mixed $v): ?string => $v === null ? null : substr((string) $v, 0, 10);

        return [$day($row['promotion_starts_on']), $day($row['promotion_ends_on'])];
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}
