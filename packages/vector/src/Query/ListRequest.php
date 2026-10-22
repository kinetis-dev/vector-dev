<?php

declare(strict_types=1);

namespace Kinetis\Vector\Query;

use DateTimeImmutable;
use Kinetis\Vector\Exception\InvalidListRequestException;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Write\InputViolation;
use Kinetis\Vector\Write\ValueConverter;

/**
 * The list endpoint's query string, validated against the descriptor:
 * every sort names a sortable field, every filter a filterable field
 * and an operator it admits, the page size stays within the resource's
 * bound, and nothing from the request survives as text — every column
 * here came from the descriptor.
 *
 * Grammar: `page`, `perPage`, `sort=-a,b`, `filter[f][op]=v` (or
 * `filter[f]=v` for equality; `in`/`nin`/`between` take `a,b,c`),
 * `filter[trashed]=only|with` on a soft-deleting resource, `q`,
 * `group=f`, `summaries=1`, `columns=a,b`, and `cursor` for keyset
 * pagination by the primary key.
 *
 * The conditions of a filter object must all hold. `filter[or][i]` holds
 * alternatives — filter objects of their own, any one of which may hold —
 * and `filter[and][i]` combines several such objects, so
 * `(a or b) and (c or d)` is `and[0][or]…&and[1][or]…`. Groups nest at
 * most MAX_DEPTH deep and hold at most MAX_CONDITIONS conditions in all.
 *
 * @phpstan-type Predicate array{column: string, op: string, value: mixed}
 * @phpstan-type FilterNode array{filters: list<Predicate>, groups: list<list<array<string, mixed>>>}
 */
final readonly class ListRequest
{
    public const string TRASHED_WITHOUT = 'without';
    public const string TRASHED_ONLY = 'only';
    public const string TRASHED_WITH = 'with';
    public const int MAX_DEPTH = 4;
    public const int MAX_CONDITIONS = 50;

    /**
     * @param list<array{field: string, order: 'asc'|'desc'}> $sort
     * @param list<Predicate> $filters the conditions that must all hold, every value typed by the field
     * @param list<list<array<string, mixed>>> $groups each a list of alternatives (a FilterNode each), one of which must hold
     * @param 'without'|'only'|'with' $trashed
     * @param array{field: string, column: string}|null $group
     * @param list<string>|null $columns a subset of the index fields to serialize, or null for all of them
     * @param string|null $cursor keyset pagination: '' for the first page, the last page's nextCursor after
     */
    private function __construct(
        public int $page,
        public int $perPage,
        public array $sort,
        public array $filters,
        public string $trashed,
        public ?string $search,
        public ?array $group,
        public bool $summaries,
        public ?array $columns,
        public ?string $cursor,
        public array $groups = [],
    ) {}

    /**
     * @param array<array-key, mixed> $params the request's query parameters
     * @param int|null $perPageCap a tighter page size bound; it never raises the resource's maxPerPage()
     */
    public static function fromQueryParams(array $params, ResourceDescriptor $resource, ValueConverter $converter, ?int $perPageCap = null): self
    {
        $page = self::positiveInt($params['page'] ?? 1, 'page');
        $perPage = self::positiveInt($params['perPage'] ?? $resource->perPage(), 'perPage');
        $cap = min($perPageCap ?? $resource->maxPerPage(), $resource->maxPerPage());

        if ($perPage > $cap) {
            throw new InvalidListRequestException("perPage must be at most {$cap}.");
        }

        $sort = self::sort($params['sort'] ?? '', $resource);
        $cursor = self::cursor($params['cursor'] ?? null, $sort, $resource);
        $search = self::search($params['q'] ?? null, $resource);
        [$filters, $groups, $trashed] = self::filters($params['filter'] ?? [], $resource, $converter);
        $group = self::group($params['group'] ?? null, $resource);
        $columns = self::columns($params['columns'] ?? null, $resource);
        $summaries = in_array($params['summaries'] ?? null, ['1', 'true', 1, true], true);

        return new self(
            $page,
            $perPage,
            $sort === [] ? $resource->defaultSort() : $sort,
            $filters,
            $trashed,
            $search === '' ? null : $search,
            $group,
            $summaries && $resource->summaryPlan() !== [],
            $columns,
            $cursor,
            $groups,
        );
    }

    /**
     * A copy without pagination, for "every record this list matches":
     * exports, summaries and bulk selections.
     */
    public function unpaged(): self
    {
        return new self(1, $this->perPage, $this->sort, $this->filters, $this->trashed, $this->search, $this->group, false, $this->columns, null, $this->groups);
    }

    /**
     * The selection an action or bulk request carries under `all`: the
     * same grammar as the list, given as a JSON object.
     *
     * @param array<array-key, mixed> $selection
     */
    public static function fromSelection(array $selection, ResourceDescriptor $resource, ValueConverter $converter): self
    {
        $params = [];

        foreach (['filter', 'q'] as $key) {
            if (isset($selection[$key])) {
                $params[$key] = $selection[$key];
            }
        }

        return self::fromQueryParams($params, $resource, $converter)->unpaged();
    }

    /** @return list<array{field: string, order: 'asc'|'desc'}> */
    private static function sort(mixed $raw, ResourceDescriptor $resource): array
    {
        if (!is_string($raw)) {
            throw new InvalidListRequestException('sort must be a comma-separated list of field names.');
        }

        $sort = [];

        foreach (array_filter(array_map('trim', explode(',', $raw))) as $entry) {
            $order = str_starts_with($entry, '-') ? 'desc' : 'asc';
            $name = ltrim($entry, '-');
            $field = $resource->field($name);

            if ($field === null || $field['sortable'] !== true) {
                throw new InvalidListRequestException("\"{$name}\" is not a sortable field of {$resource->slug()}.");
            }

            $sort[] = ['field' => $name, 'order' => $order];
        }

        return $sort;
    }

    /**
     * Keyset pagination's cursor: the last key of the page before, or ''
     * for the first page. It orders by the identifier, so it takes no sort.
     *
     * @param list<array{field: string, order: 'asc'|'desc'}> $sort
     */
    private static function cursor(mixed $cursor, array $sort, ResourceDescriptor $resource): ?string
    {
        if ($cursor === null) {
            return null;
        }

        if (!is_string($cursor)) {
            throw new InvalidListRequestException('cursor must be a string.');
        }

        if ($sort !== []) {
            throw new InvalidListRequestException('cursor pagination orders by the identifier; do not pass sort with cursor.');
        }

        // the cursor is the last key of the page before; '' asks for the first page
        if ($cursor !== '' && $resource->primaryKeyType() === 'int' && preg_match('/^-?\d{1,18}$/D', $cursor) !== 1) {
            throw new InvalidListRequestException('cursor must be an identifier of this resource.');
        }

        return $cursor;
    }

    /** `q`, trimmed: null when absent, '' when blank. */
    private static function search(mixed $search, ResourceDescriptor $resource): ?string
    {
        if ($search === null) {
            return null;
        }

        if (!is_string($search)) {
            throw new InvalidListRequestException('q must be a string.');
        }

        $search = trim($search);

        if ($search !== '' && !$resource->searchable()) {
            throw new InvalidListRequestException("{$resource->slug()} has no searchable fields.");
        }

        if (mb_strlen($search) > 200) {
            throw new InvalidListRequestException('q must be at most 200 characters.');
        }

        return $search;
    }

    /** @return array{field: string, column: string}|null */
    private static function group(mixed $groupName, ResourceDescriptor $resource): ?array
    {
        if ($groupName === null || $groupName === '') {
            return null;
        }

        if (!is_string($groupName) || !in_array($groupName, $resource->groupable(), true)) {
            throw new InvalidListRequestException('group must name a groupable field of ' . $resource->slug() . ': ' . (implode(', ', $resource->groupable()) ?: 'none') . '.');
        }

        return ['field' => $groupName, 'column' => $resource->columns()[$groupName]];
    }

    /** @return list<string>|null */
    private static function columns(mixed $rawColumns, ResourceDescriptor $resource): ?array
    {
        if ($rawColumns === null || $rawColumns === '') {
            return null;
        }

        if (!is_string($rawColumns)) {
            throw new InvalidListRequestException('columns must be a comma-separated list of field names.');
        }

        $columns = [];

        foreach (array_filter(array_map('trim', explode(',', $rawColumns))) as $name) {
            if (!in_array($name, $resource->indexFields(), true) && !in_array($name, $resource->showFields(), true)) {
                throw new InvalidListRequestException("\"{$name}\" is not a listable field of {$resource->slug()}.");
            }

            $columns[] = $name;
        }

        return $columns;
    }

    /**
     * @return array{0: list<Predicate>, 1: list<list<array<string, mixed>>>, 2: 'without'|'only'|'with'}
     */
    private static function filters(mixed $raw, ResourceDescriptor $resource, ValueConverter $converter): array
    {
        if ($raw === '' || $raw === null) {
            return [[], [], self::TRASHED_WITHOUT];
        }

        if (!is_array($raw)) {
            throw new InvalidListRequestException('filter must be an object of field names to operators.');
        }

        $trashed = self::TRASHED_WITHOUT;
        $conditions = 0;
        $node = self::node($raw, $resource, $converter, 1, $conditions, $trashed);

        return [$node['filters'], $node['groups'], $trashed];
    }

    /**
     * One filter object: its field conditions, and the groups its `or`
     * and `and` hold. `trashed` belongs to the whole filter, so only the
     * outermost object may name it.
     *
     * @param array<array-key, mixed> $raw
     * @param 'without'|'only'|'with' $trashed
     * @return FilterNode
     */
    private static function node(array $raw, ResourceDescriptor $resource, ValueConverter $converter, int $depth, int &$conditions, string &$trashed): array
    {
        $filters = [];
        $groups = [];

        foreach ($raw as $name => $spec) {
            $name = (string) $name;

            if ($name === 'trashed') {
                $trashed = self::trashed($spec, $resource, $depth);

                continue;
            }

            if ($name !== 'or' && $name !== 'and') {
                $filters = [...$filters, ...self::conditions($name, $spec, $resource, $converter, $conditions)];

                continue;
            }

            $children = self::children($name, $spec, $resource, $converter, $depth, $conditions, $trashed);

            if ($name === 'or') {
                $groups[] = $children;

                continue;
            }

            // and: every object's conditions and groups must hold, as if written here
            foreach ($children as $child) {
                $filters = [...$filters, ...$child['filters']];
                $groups = [...$groups, ...$child['groups']];
            }
        }

        return ['filters' => $filters, 'groups' => $groups];
    }

    /** @return 'without'|'only'|'with' */
    private static function trashed(mixed $spec, ResourceDescriptor $resource, int $depth): string
    {
        if ($depth > 1) {
            throw new InvalidListRequestException('filter[trashed] applies to the whole filter, not to an or/and group.');
        }

        if ($resource->softDelete() === null) {
            throw new InvalidListRequestException("{$resource->slug()} does not soft-delete; the trashed filter does not apply.");
        }

        return match ($spec) {
            self::TRASHED_ONLY, self::TRASHED_WITH, self::TRASHED_WITHOUT => $spec,
            default => throw new InvalidListRequestException('filter[trashed] must be only, with or without.'),
        };
    }

    /**
     * The filter objects an `or` or `and` holds, each one level deeper.
     *
     * @param 'or'|'and' $name
     * @param 'without'|'only'|'with' $trashed
     * @return list<FilterNode>
     */
    private static function children(string $name, mixed $spec, ResourceDescriptor $resource, ValueConverter $converter, int $depth, int &$conditions, string &$trashed): array
    {
        if ($depth >= self::MAX_DEPTH) {
            throw new InvalidListRequestException('filter groups nest at most ' . self::MAX_DEPTH . ' deep.');
        }

        if (!is_array($spec) || $spec === [] || count($spec) > 20) {
            throw new InvalidListRequestException("filter[{$name}] must hold 1 to 20 filter objects.");
        }

        $children = [];

        foreach (array_values($spec) as $child) {
            if (!is_array($child) || $child === [] || array_is_list($child)) {
                throw new InvalidListRequestException("filter[{$name}] must hold filter objects, each with a condition.");
            }

            $children[] = self::node($child, $resource, $converter, $depth + 1, $conditions, $trashed);
        }

        return $children;
    }

    /**
     * One field's conditions — `filter[f][op]=v`, or `filter[f]=v` for
     * equality — as predicates, counted against MAX_CONDITIONS.
     *
     * @return list<Predicate>
     */
    private static function conditions(string $name, mixed $spec, ResourceDescriptor $resource, ValueConverter $converter, int &$conditions): array
    {
        $field = $resource->field($name);

        if ($field === null || $field['operators'] === []) {
            throw new InvalidListRequestException("\"{$name}\" is not a filterable field of {$resource->slug()}.");
        }

        $operators = is_array($spec) ? $spec : ['eq' => $spec];
        $filters = [];

        foreach ($operators as $op => $value) {
            $op = (string) $op;

            if (!in_array($op, $field['operators'], true)) {
                throw new InvalidListRequestException("\"{$name}\" does not admit the operator \"{$op}\"; it admits " . implode(', ', $field['operators']) . '.');
            }

            if (!is_string($value) && !is_array($value)) {
                throw new InvalidListRequestException("filter[{$name}][{$op}] must be a string.");
            }

            if (++$conditions > self::MAX_CONDITIONS) {
                throw new InvalidListRequestException('a filter holds at most ' . self::MAX_CONDITIONS . ' conditions.');
            }

            $filters = [...$filters, ...self::predicate($field, $op, $value, $converter, $name)];
        }

        return $filters;
    }

    /**
     * One operator becomes one or two predicates on the field's column.
     * A date-only value against a datetime column spans the whole day,
     * so `eq` becomes a range and the bounds land on the day's edges.
     *
     * @param array<string, mixed> $field
     * @param string|array<array-key, mixed> $value
     * @return list<array{column: string, op: string, value: mixed}>
     */
    private static function predicate(array $field, string $op, string|array $value, ValueConverter $converter, string $name): array
    {
        $column = $field['column'];
        $convert = static function (string $raw) use ($field, $converter, $name): mixed {
            try {
                return $converter->fromQuery($field, trim($raw));
            } catch (InputViolation $e) {
                throw new InvalidListRequestException("filter[{$name}]: {$e->violation->message}");
            }
        };
        $list = static function (string|array $raw) use ($convert): array {
            $items = is_array($raw) ? self::texts($raw) : explode(',', $raw);
            $items = array_values(array_filter(array_map('trim', $items), static fn (string $v): bool => $v !== ''));

            if ($items === [] || count($items) > 200) {
                throw new InvalidListRequestException('a list operator takes 1 to 200 values.');
            }

            return array_map($convert, $items);
        };
        $timestamp = ($field['valueType'] ?? null) === 'timestamp';

        switch ($op) {
            case 'null':
                return [['column' => $column, 'op' => 'null', 'value' => null]];
            case 'nnull':
                return [['column' => $column, 'op' => 'nnull', 'value' => null]];
            case 'in':
            case 'nin':
                return [['column' => $column, 'op' => $op, 'value' => $list($value)]];
            case 'between':
                return [['column' => $column, 'op' => 'between', 'value' => self::between($list($value), $value, $timestamp, $name)]];
            case 'like':
            case 'nlike':
                if (!is_string($value)) {
                    throw new InvalidListRequestException("filter[{$name}][{$op}] must be a string.");
                }

                return [['column' => $column, 'op' => $op, 'value' => '%' . addcslashes(trim($value), '%_\\') . '%']];
            default:
                // a comparison operator: one typed value, below
                break;
        }

        if (!is_string($value)) {
            throw new InvalidListRequestException("filter[{$name}][{$op}] must be a string.");
        }

        $typed = $convert($value);

        if ($timestamp && self::isDay($value) && $typed instanceof DateTimeImmutable) {
            return self::dayComparison($column, $op, $typed);
        }

        return [['column' => $column, 'op' => $op, 'value' => $typed]];
    }

    /**
     * A between's two typed bounds. A date-only upper bound against a
     * datetime column lands on the end of its day.
     *
     * @param list<mixed> $bounds
     * @param string|array<array-key, mixed> $value
     * @return list<mixed>
     */
    private static function between(array $bounds, string|array $value, bool $timestamp, string $name): array
    {
        if (count($bounds) !== 2) {
            throw new InvalidListRequestException("filter[{$name}][between] takes exactly two values.");
        }

        if ($timestamp && is_array($value) === false && self::isDay(explode(',', $value)[1] ?? '') && $bounds[1] instanceof DateTimeImmutable) {
            $bounds[1] = self::dayEnd($bounds[1]);
        }

        return $bounds;
    }

    /**
     * A day against an instant column: compare against the day's edges.
     *
     * @return list<array{column: string, op: string, value: mixed}>
     */
    private static function dayComparison(string $column, string $op, DateTimeImmutable $day): array
    {
        return match ($op) {
            'eq' => [['column' => $column, 'op' => 'between', 'value' => [$day, self::dayEnd($day)]]],
            'ne' => [['column' => $column, 'op' => 'nbetween', 'value' => [$day, self::dayEnd($day)]]],
            'lte', 'gt' => [['column' => $column, 'op' => $op, 'value' => self::dayEnd($day)]],
            default => [['column' => $column, 'op' => $op, 'value' => $day]],
        };
    }

    private static function isDay(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1;
    }

    private static function dayEnd(DateTimeImmutable $day): DateTimeImmutable
    {
        return $day->setTime(23, 59, 59, 999999);
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private static function texts(array $values): array
    {
        return array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', array_values($values));
    }

    private static function positiveInt(mixed $value, string $name): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d{0,8}$/D', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidListRequestException("{$name} must be a positive integer.");
    }
}
