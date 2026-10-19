<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use DateTimeImmutable;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Vector\Exception\InvalidListRequestException;
use Kinetis\Vector\Query\ListRequest;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Write\ValueConverter;
use PHPUnit\Framework\TestCase;

/**
 * The list grammar parsed directly, against a compiled descriptor built
 * here: no application, no database. The converter types scalar values
 * only, so it needs no entity manager.
 */
final class ListRequestTest extends TestCase
{
    public function testPageAndPerPageArePositiveIntegersWithinTheCap(): void
    {
        $request = $this->parse([]);
        self::assertSame(1, $request->page);
        self::assertSame(20, $request->perPage, "the resource's page size");

        $request = $this->parse(['page' => '3', 'perPage' => '100']);
        self::assertSame(3, $request->page);
        self::assertSame(100, $request->perPage);
        self::assertSame(7, $this->parse(['page' => 7])->page);
        self::assertSame(999999999, $this->parse(['page' => '999999999'])->page);

        foreach ([0, -1, '0', '-1', 'x5', '5x', '1.5', '1234567890', ''] as $page) {
            self::assertSame('page must be a positive integer.', $this->refusal(['page' => $page]), var_export($page, true));
        }

        self::assertSame('perPage must be a positive integer.', $this->refusal(['perPage' => '0']));
        self::assertSame('perPage must be at most 100.', $this->refusal(['perPage' => '101']));

        // a caller's cap replaces the resource's bound, lower or higher
        self::assertSame('perPage must be at most 50.', $this->refusal(['perPage' => '60'], cap: 50));
        self::assertSame(50, $this->parse(['perPage' => '50'], cap: 50)->perPage);
        self::assertSame(120, $this->parse(['perPage' => '120'], cap: 150)->perPage);
    }

    public function testACursorIsTheLastKeyOfThePageBefore(): void
    {
        self::assertNull($this->parse([])->cursor);

        $first = $this->parse(['cursor' => '']);
        self::assertSame('', $first->cursor, 'the first page');
        self::assertSame([['field' => 'id', 'order' => 'asc']], $first->sort);

        foreach (['42', '-7', '123456789012345678'] as $cursor) {
            self::assertSame($cursor, $this->parse(['cursor' => $cursor])->cursor);
        }

        foreach (['x42', '42x', '4 2', '1234567890123456789', 'abc'] as $cursor) {
            self::assertSame('cursor must be an identifier of this resource.', $this->refusal(['cursor' => $cursor]), $cursor);
        }

        self::assertSame('cursor must be a string.', $this->refusal(['cursor' => ['42']]));
        self::assertSame('cursor pagination orders by the identifier; do not pass sort with cursor.', $this->refusal(['cursor' => '', 'sort' => 'name']));

        // a key that is not an integer is any text
        self::assertSame('ab-c', $this->parse(['cursor' => 'ab-c'], $this->bare())->cursor);
    }

    public function testTheSearchIsTrimmedAndBounded(): void
    {
        self::assertNull($this->parse([])->search);
        self::assertSame('lamp', $this->parse(['q' => '  lamp  '])->search);
        self::assertNull($this->parse(['q' => '   '])->search);
        self::assertSame('q must be a string.', $this->refusal(['q' => ['lamp']]));

        self::assertSame(str_repeat('é', 200), $this->parse(['q' => str_repeat('é', 200)])->search, 'characters, not bytes');
        self::assertSame('q must be at most 200 characters.', $this->refusal(['q' => str_repeat('a', 201)]));

        self::assertSame('gadgets has no searchable fields.', $this->refusal(['q' => 'lamp'], $this->bare()));
        self::assertNull($this->parse(['q' => ' '], $this->bare())->search, 'an empty search asks for nothing');
    }

    public function testAGroupNamesAGroupableField(): void
    {
        self::assertNull($this->parse([])->group);
        self::assertNull($this->parse(['group' => ''])->group);
        self::assertSame(['field' => 'stock', 'column' => 'stock_count'], $this->parse(['group' => 'stock'])->group);

        self::assertSame('group must name a groupable field of gadgets: name, stock.', $this->refusal(['group' => 'notes']));
        self::assertSame('group must name a groupable field of gadgets: name, stock.', $this->refusal(['group' => ['stock']]));
        self::assertSame('group must name a groupable field of gadgets: none.', $this->refusal(['group' => 'name'], $this->bare()));
    }

    public function testColumnsAreListableFieldsInTheOrderGiven(): void
    {
        self::assertNull($this->parse([])->columns);
        self::assertNull($this->parse(['columns' => ''])->columns);
        self::assertSame(['stock', 'notes', 'name'], $this->parse(['columns' => ' stock, notes,,name '])->columns, 'an index-only and a show-only field');

        self::assertSame('"bogus" is not a listable field of gadgets.', $this->refusal(['columns' => 'name,bogus']));
        self::assertSame('columns must be a comma-separated list of field names.', $this->refusal(['columns' => ['name']]));
    }

    public function testSummariesAreAskedForAndNeedASummaryPlan(): void
    {
        foreach (['1', 'true', 1, true] as $asked) {
            self::assertTrue($this->parse(['summaries' => $asked])->summaries, var_export($asked, true));
        }

        foreach (['0', 0, 2, 'yes', false, ''] as $asked) {
            self::assertFalse($this->parse(['summaries' => $asked])->summaries, var_export($asked, true));
        }

        self::assertFalse($this->parse([])->summaries);
        self::assertFalse($this->parse(['summaries' => '1'], $this->bare())->summaries, 'nothing to summarize');
    }

    public function testASortIsAListOfSortableFields(): void
    {
        self::assertSame([['field' => 'id', 'order' => 'asc']], $this->parse([])->sort, 'the default sort');
        self::assertSame(
            [['field' => 'name', 'order' => 'desc'], ['field' => 'stock', 'order' => 'asc']],
            $this->parse(['sort' => ' -name , stock,'])->sort,
        );

        self::assertSame('"notes" is not a sortable field of gadgets.', $this->refusal(['sort' => 'name,notes']));
        self::assertSame('"bogus" is not a sortable field of gadgets.', $this->refusal(['sort' => '-bogus']));
        self::assertSame('sort must be a comma-separated list of field names.', $this->refusal(['sort' => ['name']]));
    }

    public function testUnpagedKeepsTheSelectionAndDropsThePagination(): void
    {
        $request = $this->parse(['page' => '3', 'perPage' => '50', 'sort' => '-name', 'q' => 'lamp', 'summaries' => '1', 'columns' => 'name', 'group' => 'stock', 'filter' => ['stock' => ['gt' => '1'], 'trashed' => 'with', 'or' => [['name' => 'a'], ['name' => 'b']]]]);
        self::assertTrue($request->summaries);
        $unpaged = $request->unpaged();

        self::assertSame(1, $unpaged->page);
        self::assertSame(50, $unpaged->perPage);
        self::assertFalse($unpaged->summaries);
        self::assertNull($unpaged->cursor);
        self::assertSame($request->sort, $unpaged->sort);
        self::assertSame($request->filters, $unpaged->filters);
        self::assertSame($request->groups, $unpaged->groups);
        self::assertSame('with', $unpaged->trashed);
        self::assertSame('lamp', $unpaged->search);
        self::assertSame($request->group, $unpaged->group);
        self::assertSame(['name'], $unpaged->columns);

        self::assertNull($this->parse(['cursor' => '42'])->unpaged()->cursor);
    }

    public function testASelectionCarriesTheFilterAndTheSearchOnly(): void
    {
        // a JSON selection types its list values; page, sort and perPage are not part of it
        $selection = ListRequest::fromSelection(['filter' => ['stock' => ['in' => [5, 7]]], 'q' => 'lamp', 'page' => '4', 'sort' => 'name', 'perPage' => '1000'], $this->gadgets(), $this->converter());

        self::assertSame([['column' => 'stock_count', 'op' => 'in', 'value' => [5, 7]]], $selection->filters);
        self::assertSame('lamp', $selection->search);
        self::assertSame(1, $selection->page);
        self::assertSame(20, $selection->perPage);
        self::assertSame([['field' => 'id', 'order' => 'asc']], $selection->sort);
        self::assertNull($selection->cursor);
    }

    public function testFiltersBecomeTypedPredicatesOnTheFieldsColumn(): void
    {
        self::assertSame([[], [], 'without'], [$this->parse([])->filters, $this->parse([])->groups, $this->parse([])->trashed]);
        self::assertSame([], $this->parse(['filter' => ''])->filters);
        self::assertSame([['column' => 'name', 'op' => 'eq', 'value' => 'lamp']], $this->parse(['filter' => ['name' => ' lamp ']])->filters);
        self::assertSame(
            [['column' => 'stock_count', 'op' => 'gte', 'value' => 5], ['column' => 'stock_count', 'op' => 'lt', 'value' => 9], ['column' => 'name', 'op' => 'ne', 'value' => 'x']],
            $this->parse(['filter' => ['stock' => ['gte' => ' 5 ', 'lt' => '9'], 'name' => ['ne' => 'x']]])->filters,
        );

        self::assertSame([['column' => 'stock_count', 'op' => 'in', 'value' => [5, 7]]], $this->parse(['filter' => ['stock' => ['in' => '5, ,7,']]])->filters);
        self::assertSame([['column' => 'stock_count', 'op' => 'nin', 'value' => [5, 7]]], $this->parse(['filter' => ['stock' => ['nin' => ['a' => '5', 'b' => ['x'], 'c' => ' 7']]]])->filters, 'a value that is not scalar is dropped');
        self::assertSame([['column' => 'stock_count', 'op' => 'between', 'value' => [1, 5]]], $this->parse(['filter' => ['stock' => ['between' => '1,5']]])->filters);

        // like matches the text anywhere, its wildcards escaped
        self::assertSame([['column' => 'name', 'op' => 'like', 'value' => '%50\%\_off\\\\%']], $this->parse(['filter' => ['name' => ['like' => ' 50%_off\\ ']]])->filters);
        self::assertSame([['column' => 'name', 'op' => 'nlike', 'value' => '%a\_b%']], $this->parse(['filter' => ['name' => ['nlike' => 'a_b']]])->filters);

        self::assertSame(
            [['column' => 'released_at', 'op' => 'null', 'value' => null], ['column' => 'name', 'op' => 'nnull', 'value' => null]],
            $this->parse(['filter' => ['releasedAt' => ['null' => ''], 'name' => ['nnull' => '1']]])->filters,
        );
    }

    public function testFilterRefusalsNameTheFieldAndTheOperator(): void
    {
        self::assertSame('filter must be an object of field names to operators.', $this->refusal(['filter' => 'stock']));
        self::assertSame('"bogus" is not a filterable field of gadgets.', $this->refusal(['filter' => ['bogus' => 'x']]));
        self::assertSame('"notes" is not a filterable field of gadgets.', $this->refusal(['filter' => ['notes' => 'x']]));
        self::assertSame('"0" is not a filterable field of gadgets.', $this->refusal(['filter' => ['0' => 'x']]), 'a numeric key is a field name too');
        self::assertSame('"stock" does not admit the operator "like"; it admits eq, ne, lt, lte, gt, gte, between, in, nin.', $this->refusal(['filter' => ['stock' => ['like' => '5']]]));
        self::assertSame('filter[stock][eq] must be a string.', $this->refusal(['filter' => ['stock' => ['eq' => 5]]]));
        self::assertSame('filter[stock][eq] must be a string.', $this->refusal(['filter' => ['stock' => ['eq' => ['5']]]]));
        self::assertSame('filter[name][like] must be a string.', $this->refusal(['filter' => ['name' => ['like' => ['a']]]]));
        self::assertSame('filter[stock]: must be an integer.', $this->refusal(['filter' => ['stock' => ['gt' => 'abc']]]));
        self::assertSame('filter[stock][between] takes exactly two values.', $this->refusal(['filter' => ['stock' => ['between' => '1']]]));
    }

    public function testListOperatorsTakeOneToTwoHundredValues(): void
    {
        self::assertSame(range(1, 200), $this->parse(['filter' => ['stock' => ['in' => implode(',', range(1, 200))]]])->filters[0]['value']);

        foreach ([',', ' , ', [['x']], implode(',', range(1, 201))] as $values) {
            self::assertSame('a list operator takes 1 to 200 values.', $this->refusal(['filter' => ['stock' => ['in' => $values]]]));
        }
    }

    public function testTrashedAppliesToTheWholeFilter(): void
    {
        self::assertSame('without', $this->parse([])->trashed);

        foreach (['only', 'with', 'without'] as $trashed) {
            self::assertSame($trashed, $this->parse(['filter' => ['trashed' => $trashed]])->trashed);
        }

        $request = $this->parse(['filter' => ['trashed' => 'with', 'stock' => '1']]);
        self::assertSame(['with', [['column' => 'stock_count', 'op' => 'eq', 'value' => 1]]], [$request->trashed, $request->filters], 'the conditions after it still apply');

        self::assertSame('filter[trashed] must be only, with or without.', $this->refusal(['filter' => ['trashed' => 'all']]));
        self::assertSame('gadgets does not soft-delete; the trashed filter does not apply.', $this->refusal(['filter' => ['trashed' => 'only']], $this->bare()));
        self::assertSame('filter[trashed] applies to the whole filter, not to an or/and group.', $this->refusal(['filter' => ['or' => [['trashed' => 'only']]]]));
        self::assertSame('filter[trashed] applies to the whole filter, not to an or/and group.', $this->refusal(['filter' => ['and' => [['trashed' => 'only']]]]));
    }

    public function testOrHoldsAlternativesAndAndMergesItsObjects(): void
    {
        $request = $this->parse(['filter' => ['name' => 'a', 'or' => [['stock' => ['gt' => '1']], ['name' => 'b', 'or' => [['stock' => '2'], ['stock' => '3']]]]]]);
        self::assertSame([['column' => 'name', 'op' => 'eq', 'value' => 'a']], $request->filters);
        self::assertSame([[
            ['filters' => [['column' => 'stock_count', 'op' => 'gt', 'value' => 1]], 'groups' => []],
            ['filters' => [['column' => 'name', 'op' => 'eq', 'value' => 'b']], 'groups' => [[
                ['filters' => [['column' => 'stock_count', 'op' => 'eq', 'value' => 2]], 'groups' => []],
                ['filters' => [['column' => 'stock_count', 'op' => 'eq', 'value' => 3]], 'groups' => []],
            ]]],
        ]], $request->groups);

        // (a or b) and (c or d), beside a condition of its own
        $request = $this->parse(['filter' => ['and' => [['stock' => ['gt' => '1'], 'or' => [['name' => 'a'], ['name' => 'b']]], ['name' => ['ne' => 'c'], 'or' => [['stock' => '2'], ['stock' => '3']]]]]]);
        self::assertSame([['column' => 'stock_count', 'op' => 'gt', 'value' => 1], ['column' => 'name', 'op' => 'ne', 'value' => 'c']], $request->filters);
        self::assertCount(2, $request->groups);
        self::assertSame('a', $request->groups[0][0]['filters'][0]['value']);
        self::assertSame(3, $request->groups[1][1]['filters'][0]['value']);

        self::assertSame('filter[or] must hold 1 to 20 filter objects.', $this->refusal(['filter' => ['or' => []]]));
        self::assertSame('filter[and] must hold 1 to 20 filter objects.', $this->refusal(['filter' => ['and' => 'x']]));
        self::assertSame('filter[or] must hold 1 to 20 filter objects.', $this->refusal(['filter' => ['or' => array_fill(0, 21, ['stock' => '1'])]]));
        self::assertCount(20, $this->parse(['filter' => ['or' => array_fill(0, 20, ['stock' => '1'])]])->groups[0]);
        self::assertSame('filter[or] must hold filter objects, each with a condition.', $this->refusal(['filter' => ['or' => [['1', '2']]]]));
        self::assertSame('filter[or] must hold filter objects, each with a condition.', $this->refusal(['filter' => ['or' => [[]]]]));
        self::assertSame('filter[and] must hold filter objects, each with a condition.', $this->refusal(['filter' => ['and' => ['x']]]));
    }

    public function testGroupsNestFourDeepAndHoldFiftyConditions(): void
    {
        $deepest = $this->parse(['filter' => ['or' => [['or' => [['or' => [['stock' => '1']]]]]]]]);
        self::assertSame([['column' => 'stock_count', 'op' => 'eq', 'value' => 1]], $deepest->groups[0][0]['groups'][0][0]['groups'][0][0]['filters']);
        self::assertSame('filter groups nest at most 4 deep.', $this->refusal(['filter' => ['or' => [['or' => [['or' => [['or' => [['stock' => '1']]]]]]]]]]));
        self::assertSame('filter groups nest at most 4 deep.', $this->refusal(['filter' => ['and' => [['and' => [['and' => [['and' => [['stock' => '1']]]]]]]]]]));

        // 20 objects of two conditions, and 5 more: 50 across the whole filter
        $fifty = ['and' => array_fill(0, 20, ['stock' => ['gt' => '1', 'lt' => '9']]), 'or' => array_fill(0, 5, ['stock' => ['gt' => '1', 'lt' => '9']])];
        self::assertCount(40, $this->parse(['filter' => $fifty])->filters);
        self::assertSame('a filter holds at most 50 conditions.', $this->refusal(['filter' => [...$fifty, 'name' => 'x']]));
    }

    public function testADayAgainstAnInstantColumnSpansTheDay(): void
    {
        $day = ['2026-03-04 00:00:00.000000', '2026-03-04 23:59:59.999999'];

        self::assertSame([['released_at', 'between', $day]], $this->instants(['eq' => '2026-03-04']));
        self::assertSame([['released_at', 'between', $day]], $this->instants(['eq' => ' 2026-03-04 ']));
        self::assertSame([['released_at', 'nbetween', $day]], $this->instants(['ne' => '2026-03-04']));
        self::assertSame([['released_at', 'lte', $day[1]]], $this->instants(['lte' => '2026-03-04']));
        self::assertSame([['released_at', 'gt', $day[1]]], $this->instants(['gt' => '2026-03-04']));
        self::assertSame([['released_at', 'lt', $day[0]]], $this->instants(['lt' => '2026-03-04']));
        self::assertSame([['released_at', 'gte', $day[0]]], $this->instants(['gte' => '2026-03-04']));

        // an instant is compared as given
        self::assertSame([['released_at', 'eq', '2026-03-04 10:30:00.000000']], $this->instants(['eq' => '2026-03-04T10:30:00']));
        self::assertSame([['released_at', 'lte', '2026-03-04 10:30:00.000000']], $this->instants(['lte' => '2026-03-04 10:30']));

        // between: a day as the upper bound runs to the day's end
        self::assertSame([['released_at', 'between', ['2026-03-01 00:00:00.000000', '2026-03-04 23:59:59.999999']]], $this->instants(['between' => '2026-03-01,2026-03-04']));
        self::assertSame([['released_at', 'between', ['2026-03-01 10:00:00.000000', '2026-03-04 23:59:59.999999']]], $this->instants(['between' => '2026-03-01T10:00:00,2026-03-04']));
        self::assertSame([['released_at', 'between', ['2026-03-01 00:00:00.000000', '2026-03-04 12:00:00.000000']]], $this->instants(['between' => '2026-03-01,2026-03-04T12:00:00']));
        self::assertSame([['released_at', 'between', ['2026-03-01 00:00:00.000000', '2026-03-04 00:00:00.000000']]], $this->instants(['between' => ['2026-03-01', '2026-03-04']]), 'bounds given as a list are taken as they are');

        // a day against a column that is not an instant is a plain value
        self::assertSame([['column' => 'name', 'op' => 'eq', 'value' => '2026-03-04']], $this->parse(['filter' => ['name' => '2026-03-04']])->filters);
    }

    /**
     * The predicates of a filter on releasedAt, each instant as UTC text.
     *
     * @param array<string, mixed> $operators
     * @return list<array{0: mixed, 1: mixed, 2: mixed}>
     */
    private function instants(array $operators): array
    {
        $text = static fn (mixed $v): mixed => $v instanceof DateTimeImmutable ? $v->format('Y-m-d H:i:s.u') : $v;

        return array_map(
            static fn (array $p): array => [$p['column'], $p['op'], is_array($p['value']) ? array_map($text, $p['value']) : $text($p['value'])],
            $this->parse(['filter' => ['releasedAt' => $operators]])->filters,
        );
    }

    /** @param array<array-key, mixed> $params */
    private function parse(array $params, ?ResourceDescriptor $resource = null, ?int $cap = null): ListRequest
    {
        return ListRequest::fromQueryParams($params, $resource ?? $this->gadgets(), $this->converter(), $cap);
    }

    /** @param array<array-key, mixed> $params */
    private function refusal(array $params, ?ResourceDescriptor $resource = null, ?int $cap = null): string
    {
        try {
            $this->parse($params, $resource, $cap);
        } catch (InvalidListRequestException $e) {
            return $e->getMessage();
        }

        self::fail('The list request was accepted: ' . json_encode($params));
    }

    private function converter(): ValueConverter
    {
        $registry = VectorRegistry::fromArray(['version' => VectorRegistry::VERSION, 'resources' => ['gadgets' => $this->data()], 'settings' => [], 'dashboards' => []]);
        $managers = EntityManagerRegistry::create(OrmFactoryRegistry::create([], MetadataRegistry::fromClasses([])));

        return new ValueConverter($registry, $managers, new RequestScope(new AppScope()));
    }

    private function gadgets(): ResourceDescriptor
    {
        return new ResourceDescriptor($this->data());
    }

    /** The same fields with an opaque key, and no search, grouping, summaries or soft delete. */
    private function bare(): ResourceDescriptor
    {
        $data = $this->data();
        $data['primaryKey']['type'] = 'string';
        $data['searchColumns'] = [];
        $data['summaryPlan'] = [];
        $data['softDelete'] = null;
        $data['table_view']['groupable'] = [];

        return new ResourceDescriptor($data);
    }

    /**
     * A compiled resource: stock is on the index only, notes on the show
     * page only and neither sortable nor filterable.
     *
     * @return array<string, mixed>
     */
    private function data(): array
    {
        $field = static fn (string $name, string $type, string $valueType, string $column, bool $sortable, array $operators): array => ['name' => $name, 'type' => $type, 'valueType' => $valueType, 'column' => $column, 'sortable' => $sortable, 'operators' => $operators];

        return [
            'slug' => 'gadgets',
            'primaryKey' => ['field' => 'id', 'column' => 'id', 'type' => 'int'],
            'fields' => [
                $field('id', 'id', 'int', 'id', true, []),
                $field('name', 'string', 'string', 'name', true, ['eq', 'ne', 'like', 'nlike', 'in', 'nin', 'null', 'nnull']),
                $field('stock', 'number', 'int', 'stock_count', true, ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'between', 'in', 'nin']),
                $field('releasedAt', 'datetime', 'timestamp', 'released_at', true, ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'between', 'null', 'nnull']),
                $field('notes', 'textarea', 'string', 'notes', false, []),
            ],
            'columns' => ['id' => 'id', 'name' => 'name', 'stock' => 'stock_count', 'releasedAt' => 'released_at', 'notes' => 'notes'],
            'searchColumns' => ['name' => 'name'],
            'summaryPlan' => ['stock' => ['function' => 'sum', 'column' => 'stock_count']],
            'softDelete' => ['field' => 'deletedAt', 'column' => 'deleted_at'],
            'table_view' => ['columns' => ['id', 'name', 'stock'], 'extra' => [], 'groupable' => ['name', 'stock'], 'defaultSort' => [['field' => 'id', 'order' => 'asc']], 'perPage' => 20, 'maxPerPage' => 100],
            'show_view' => ['fields' => ['id', 'name', 'releasedAt', 'notes'], 'extra' => []],
        ];
    }
}
