<?php

declare(strict_types=1);

namespace Kinetis\Vector\Query;

use Kinetis\Authorization\AuthorizationResponse;
use Kinetis\Authorization\Gate;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\QueryBuilder\Conditions;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Actions\VectorAction;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Database\Connections;
use Kinetis\Vector\Database\Sql;
use Kinetis\Vector\Auth\ResourcePolicyInterface;
use Kinetis\Vector\Exception\ForbiddenOperationException;
use Kinetis\Vector\Exception\InvalidListRequestException;
use Kinetis\Vector\Exception\RecordNotFoundException;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Write\InputViolation;
use Kinetis\Vector\Write\ValueConverter;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\VectorResource;

/**
 * The read side of the admin API. Request-scoped: it holds the request's
 * user and container. Lists run on the query builder with columns the
 * descriptor names — a request only ever chooses among them — and every
 * relation display value is loaded in one statement per relation field,
 * never per row.
 */
final readonly class ResourceService
{
    public function __construct(
        private EntityManagerRegistry $managers,
        private Connections $connections,
        private RequestScope $scope,
        private VectorRegistry $registry,
        private Gate $gate,
        private CurrentUserInterface $user,
        private RowSerializer $serializer,
        private ValueConverter $converter,
        private \Kinetis\Vector\VectorDefaults $defaults,
    ) {}

    public function registry(): VectorRegistry
    {
        return $this->registry;
    }

    /**
     * A resource as this request's user sees it: the compiled descriptor
     * without the fields the resource's policy hides from them
     * (`viewField`). Every request path reads resources through here, so
     * a hidden field can be neither read nor filtered, sorted, searched,
     * grouped, exported or written.
     */
    public function resource(string $slug): ResourceDescriptor
    {
        return $this->visible($this->registry->resource($slug));
    }

    /** @return array<string, ResourceDescriptor> every resource, as this user sees it */
    public function resources(): array
    {
        return array_map($this->visible(...), $this->registry->resources());
    }

    public function visible(ResourceDescriptor $resource): ResourceDescriptor
    {
        $policy = $this->policy($resource);
        $hidden = [];

        foreach (array_keys($resource->fields()) as $name) {
            if (!$this->gate->allows($this->user, static fn (CurrentUserInterface $user): bool|AuthorizationResponse => $policy->viewField($user, $name))) {
                $hidden[] = $name;
            }
        }

        return $resource->withoutFields($hidden)->withDefaultCurrency($this->defaults->currency);
    }

    /**
     * A fresh builder on the connection $resource's entity lives on: the
     * request's manager for that connection hands it out, so reads and
     * the writer's flush see the same database.
     */
    private function query(ResourceDescriptor $resource): Query
    {
        return $this->managers->manager($resource->connection())->builder();
    }

    /** @return 'mysql'|'pgsql' */
    private function dialect(ResourceDescriptor $resource): string
    {
        return $this->connections->dialect($resource->connection());
    }

    public function policy(ResourceDescriptor $resource): ResourcePolicyInterface
    {
        $class = $resource->policy() ?? AllowAllPolicy::class;
        $policy = $this->scope->get($class);

        return $policy instanceof ResourcePolicyInterface
            ? $policy
            : throw new \LogicException("{$class} is not a ResourcePolicyInterface.");
    }

    /**
     * What this user may do with the resource, as the descriptor publishes it.
     *
     * @return array<string, bool>
     */
    public function permissions(ResourceDescriptor $resource): array
    {
        $policy = $this->policy($resource);

        return [
            'viewAny' => $this->gate->allows($this->user, $policy->viewAny(...)),
            'create' => $resource->creation()['possible'] && $this->gate->allows($this->user, $policy->create(...)),
            // update and delete are decided per record; true means "may be attempted"
            'update' => true,
            'delete' => true,
            'export' => $this->gate->allows($this->user, $policy->export(...)),
            'setDefaultView' => $this->gate->allows($this->user, $policy->setDefaultView(...)),
        ];
    }

    /** Refuses unless the user may set the view everyone's list opens with. */
    public function authorizeDefaultView(ResourceDescriptor $resource): void
    {
        $this->gate->authorize($this->user, $this->policy($resource)->setDefaultView(...));
    }

    /**
     * One page of the resource under the request's grammar: the bounded
     * plan of §4.6 — scope, soft-delete predicate, filters, search,
     * order (always terminated by the key), one page, one statement per
     * relation field, one aggregate statement when summaries are asked.
     *
     * @return array<string, mixed> the Paginator envelope, or `{data, nextCursor, hasMore, perPage}` under a cursor
     */
    public function list(ResourceDescriptor $resource, ListRequest $request, ?\Closure $restrict = null): array
    {
        $this->gate->authorize($this->user, $this->policy($resource)->viewAny(...));

        $fields = $this->listFields($resource, $request);
        $query = $this->constrained($resource, $fields, $request, $restrict);

        if ($request->cursor !== null) {
            $page = $query->cursorPaginate($request->perPage, $request->cursor === '' ? null : $request->cursor, $resource->primaryKeyColumn());
            /** @var list<array<string, mixed>> $rows */
            $rows = $page->data;
            $relations = $this->loadRelations($resource, $fields, $rows);

            return [
                'data' => array_map(fn (array $row): array => $this->serializer->serialize($resource, $row, $fields, $relations), $rows),
                'nextCursor' => $page->nextCursor,
                'hasMore' => $page->hasMore,
                'perPage' => $request->perPage,
            ];
        }

        if ($request->group !== null) {
            $query->orderBy($request->group['column'], 'ASC');
        }

        foreach ($request->sort as $sort) {
            $query->orderBy($resource->columns()[$sort['field']], strtoupper($sort['order']));
        }

        if (!in_array($resource->primaryKeyField(), array_column($request->sort, 'field'), true)) {
            $query->orderBy($resource->primaryKeyColumn(), 'ASC');
        }

        $page = $query->paginate($request->perPage, $request->page);
        /** @var list<array<string, mixed>> $rows */
        $rows = $page->data;
        $relations = $this->loadRelations($resource, $fields, $rows);

        $envelope = [
            'data' => array_map(fn (array $row): array => $this->serializer->serialize($resource, $row, $fields, $relations), $rows),
            'currentPage' => $page->currentPage,
            'perPage' => $page->perPage,
            'total' => $page->total,
            'lastPage' => $page->lastPage,
        ];

        if ($request->summaries) {
            $envelope['summaries'] = $this->summaries($resource, $request, $restrict);
        }

        return $envelope;
    }

    /**
     * Every identifier the request's selection matches, for "all
     * matching" bulk operations. Bounded by the resource's bulk limit:
     * a larger match is refused rather than truncated, since a partial
     * bulk operation is worse than none.
     *
     * @return list<int|string>
     */
    public function matchingIds(ResourceDescriptor $resource, ListRequest $request, ?int $limit = null): array
    {
        $this->gate->authorize($this->user, $this->policy($resource)->viewAny(...));
        $limit ??= $resource->bulkLimit();
        $query = $this->constrained($resource, null, $request, null)->select($resource->primaryKeyColumn());
        $count = $query->count();

        if ($count > $limit) {
            throw new ForbiddenOperationException("The selection matches {$count} records; at most {$limit} can be handled at once. Narrow the filter.");
        }

        $ids = [];

        foreach ($query->orderBy($resource->primaryKeyColumn(), 'ASC')->get() as $row) {
            $ids[] = $this->serializer->key($row, $resource);
        }

        return $ids;
    }

    /**
     * The whole selection as rows, in pages of 1000 by keyset, for an
     * export. Refuses a match above the resource's export limit.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function stream(ResourceDescriptor $resource, ListRequest $request, ?int $limit = null): \Generator
    {
        $this->gate->authorize($this->user, $this->policy($resource)->viewAny(...));
        $this->gate->authorize($this->user, $this->policy($resource)->export(...));

        $fields = $this->listFields($resource, $request);
        $total = $this->exportCount($resource, $request);
        $limit ??= $resource->exportLimit();

        if ($total > $limit) {
            throw new ForbiddenOperationException("The selection matches {$total} records; this export serializes at most {$limit}. Narrow the filter.");
        }

        $cursor = null;

        do {
            $page = $this->constrained($resource, $fields, $request, null)->cursorPaginate(1000, $cursor, $resource->primaryKeyColumn());
            /** @var list<array<string, mixed>> $rows */
            $rows = $page->data;
            $relations = $this->loadRelations($resource, $fields, $rows);

            foreach ($rows as $row) {
                yield $this->serializer->serialize($resource, $row, $fields, $relations);
            }

            $cursor = $page->nextCursor;
        } while ($page->hasMore && $cursor !== null);
    }

    /**
     * The menu badges of every resource this user may list: the count of
     * each badge's filter, as the user's own list counts it. A badge that
     * filters on a field this user may not see is left out.
     *
     * @return array<string, array{count: int, color: string, label: string|null}> slug => badge
     */
    public function badges(): array
    {
        $badges = [];

        foreach ($this->resources() as $resource) {
            $badge = $resource->badge();

            if ($badge === null || !$this->gate->allows($this->user, $this->policy($resource)->viewAny(...))) {
                continue;
            }

            if (!self::namesVisibleFields($badge['filter'], $resource)) {
                continue;
            }

            $request = ListRequest::fromQueryParams(['filter' => $badge['filter']], $resource, $this->converter);
            $badges[$resource->slug()] = [
                'count' => $this->constrained($resource, null, $request, null)->count(),
                'color' => $badge['color'],
                'label' => $badge['label'],
            ];
        }

        return $badges;
    }

    /** @param array<string, mixed> $filter */
    private static function namesVisibleFields(array $filter, ResourceDescriptor $resource): bool
    {
        foreach ($filter as $name => $conditions) {
            if (in_array($name, ['or', 'and'], true) && is_array($conditions)) {
                foreach ($conditions as $child) {
                    if (!self::namesVisibleFields(is_array($child) ? $child : [], $resource)) {
                        return false;
                    }
                }
            } elseif ($name !== 'trashed' && $resource->field($name) === null) {
                return false;
            }
        }

        return true;
    }

    /** How many rows an export of this request would serialize. */
    public function exportCount(ResourceDescriptor $resource, ListRequest $request): int
    {
        $this->gate->authorize($this->user, $this->policy($resource)->viewAny(...));

        return $this->constrained($resource, null, $request, null)->count();
    }

    /**
     * Global search: the first matches of every globally searchable
     * resource this user may list, one bounded statement per resource.
     *
     * A result carries its resource's subtitle field as stored, and the
     * image field to fetch its thumbnail from when the record has one.
     *
     * @return list<array{resource: string, resourceLabel: string, id: int|string, title: string, subtitle: string|null, image: string|null}>
     */
    public function search(string $term, int $perResource = 5): array
    {
        $term = trim($term);
        $results = [];

        if ($term === '' || mb_strlen($term) > 200) {
            return $results;
        }

        foreach ($this->resources() as $resource) {
            if (!$resource->globallySearchable() || !$this->gate->allows($this->user, $this->policy($resource)->viewAny(...))) {
                continue;
            }

            ['subtitle' => $subtitle, 'image' => $image] = $resource->searchResult();
            $subtitleColumn = $subtitle === null ? null : $resource->columns()[$subtitle] ?? null;
            $imageColumn = $image === null ? null : $resource->columns()[$image] ?? null;
            $query = $this->query($resource)->table($resource->table())
                ->select(...array_values(array_unique(array_filter([$resource->primaryKeyColumn(), $resource->titleColumn(), $subtitleColumn, $imageColumn]))))
                ->limit($perResource)
                ->orderBy($resource->primaryKeyColumn(), 'DESC');
            $instance = $this->scope->get($resource->resourceClass());

            if ($instance instanceof VectorResource) {
                $query = $instance->scope($query, $this->user);
            }

            $this->applySoftDelete($resource, $query, ListRequest::TRASHED_WITHOUT);
            $this->applySearch($resource, $query, $term);

            foreach ($query->get() as $row) {
                $results[] = [
                    'resource' => $resource->slug(),
                    'resourceLabel' => $resource->labelSingular(),
                    'id' => $this->serializer->key($row, $resource),
                    'title' => (string) $row[$resource->titleColumn()],
                    'subtitle' => $subtitleColumn === null || $row[$subtitleColumn] === null ? null : (string) $row[$subtitleColumn],
                    'image' => $imageColumn !== null && is_string($row[$imageColumn]) && $row[$imageColumn] !== '' ? $image : null,
                ];
            }
        }

        return $results;
    }

    /**
     * The fields a list serializes: the requested subset of the index
     * fields, or all of them, plus the ones their display depends on.
     *
     * @return list<string>
     */
    private function listFields(ResourceDescriptor $resource, ListRequest $request): array
    {
        if ($request->columns === null) {
            return $resource->indexFields();
        }

        $fields = $request->columns;

        foreach ($request->columns as $name) {
            $currency = $resource->field($name)['props']['currencyField'] ?? null;
            // a related record's currency comes with the relation
            $currency = is_string($currency) ? explode('.', $currency, 2)[0] : null;

            if (is_string($currency) && !in_array($currency, $fields, true)) {
                $fields[] = $currency;
            }
        }

        return $fields;
    }

    /**
     * The list's base query with everything but order and pagination
     * applied: the scope, the soft-delete predicate, the filters, the
     * search and the caller's own restriction.
     *
     * @param list<string>|null $fields null selects nothing yet: for a count, an id list or an aggregate
     */
    private function constrained(ResourceDescriptor $resource, ?array $fields, ListRequest $request, ?\Closure $restrict): Query
    {
        $query = $this->baseQuery($resource, $fields);

        if ($restrict !== null) {
            $restrict($query);
        }

        $this->applySoftDelete($resource, $query, $request->trashed);

        $this->applyNode($resource, $query, ['filters' => $request->filters, 'groups' => $request->groups]);

        if ($request->search !== null) {
            $this->applySearch($resource, $query, $request->search);
        }

        return $query;
    }

    private function applySoftDelete(ResourceDescriptor $resource, Query $query, string $trashed): void
    {
        $soft = $resource->softDelete();

        if ($soft === null) {
            return;
        }

        match ($trashed) {
            ListRequest::TRASHED_ONLY => $query->where($soft['column'], '!=', null),
            ListRequest::TRASHED_WITH => null,
            default => $query->where($soft['column'], '=', null),
        };
    }

    /**
     * A filter object: its conditions, then each group as one
     * parenthesised disjunction of its alternatives.
     *
     * @param array{filters: list<array{column: string, op: string, value: mixed}>, groups: list<list<array<string, mixed>>>} $node
     */
    private function applyNode(ResourceDescriptor $resource, Query|Conditions $target, array $node): void
    {
        foreach ($node['filters'] as $filter) {
            $this->applyFilter($resource, $target, $filter);
        }

        foreach ($node['groups'] as $alternatives) {
            $target->whereGroup(function (Conditions $group) use ($resource, $alternatives): void {
                foreach ($alternatives as $index => $alternative) {
                    /** @var array{filters: list<array{column: string, op: string, value: mixed}>, groups: list<list<array<string, mixed>>>} $alternative */
                    $apply = function (Conditions $conditions) use ($resource, $alternative): void {
                        $this->applyNode($resource, $conditions, $alternative);
                    };
                    $index === 0 ? $group->whereGroup($apply) : $group->orWhereGroup($apply);
                }
            });
        }
    }

    /** @param array{column: string, op: string, value: mixed} $filter */
    private function applyFilter(ResourceDescriptor $resource, Query|Conditions $query, array $filter): void
    {
        $dialect = $this->dialect($resource);
        $column = $filter['column'];
        $value = $filter['value'];
        $bind = static fn (mixed $v): mixed => match (true) {
            $v instanceof \DateTimeInterface => $v->format('Y-m-d H:i:s.u'),
            $v instanceof \Kinetis\Orm\Date => (string) $v,
            default => $v,
        };

        match ($filter['op']) {
            'eq' => $query->where($column, '=', $bind($value)),
            'ne' => $query->where($column, '!=', $bind($value)),
            'lt' => $query->where($column, '<', $bind($value)),
            'lte' => $query->where($column, '<=', $bind($value)),
            'gt' => $query->where($column, '>', $bind($value)),
            'gte' => $query->where($column, '>=', $bind($value)),
            'like' => $query->whereRaw(Sql::like($dialect, $column), [$value]),
            'nlike' => $query->whereGroup(static fn (Conditions $c) => $c->whereRaw(Sql::like($dialect, $column, true), [$value])->orWhere($column, '=', null)),
            'in' => $query->whereIn($column, array_values(array_map($bind, is_array($value) ? $value : [$value]))),
            'nin' => $query->whereGroup(static fn (Conditions $c) => $c->whereNotIn($column, array_values(array_map($bind, is_array($value) ? $value : [$value])))->orWhere($column, '=', null)),
            'between' => $query->whereBetween($column, $bind($value[0]), $bind($value[1])),
            'nbetween' => $query->whereGroup(static fn (Conditions $c) => $c->whereNotBetween($column, $bind($value[0]), $bind($value[1]))->orWhere($column, '=', null)),
            'null' => $query->where($column, '=', null),
            'nnull' => $query->where($column, '!=', null),
            default => throw new InvalidListRequestException("Unknown operator {$filter['op']}."),
        };
    }

    /**
     * `q`: a case-insensitive match over every searchable column, as one
     * parenthesized OR group — LIKE on MySQL, whose collations already
     * ignore case, ILIKE on PostgreSQL, whose LIKE does not.
     */
    private function applySearch(ResourceDescriptor $resource, Query $query, string $term): void
    {
        $pattern = '%' . addcslashes($term, '%_\\') . '%';
        $columns = array_values($resource->searchColumns());
        $dialect = $this->dialect($resource);

        $query->whereGroup(static function (Conditions $group) use ($columns, $pattern, $dialect): void {
            foreach ($columns as $i => $column) {
                $group->whereRaw(Sql::like($dialect, $column), [$pattern], $i === 0 ? 'AND' : 'OR');
            }
        });
    }

    /**
     * Every declared summary in one aggregate statement over the same
     * rows the list matched.
     *
     * @return array<string, int|float|null>
     */
    private function summaries(ResourceDescriptor $resource, ListRequest $request, ?\Closure $restrict): array
    {
        $query = $this->constrained($resource, null, $request, $restrict);
        $expressions = [];
        $dialect = $this->dialect($resource);

        foreach ($resource->summaryPlan() as $name => ['function' => $function, 'column' => $column]) {
            $expressions[] = strtoupper($function) . '(' . Sql::quote($dialect, $column) . ') AS ' . Sql::quote($dialect, "s_{$name}");
        }

        $row = $query->selectRaw(implode(', ', $expressions))->first();
        $summaries = [];

        foreach ($resource->summaryPlan() as $name => ['function' => $function]) {
            $value = is_array($row) ? ($row["s_{$name}"] ?? null) : null;
            $field = $resource->field($name);
            $summaries[$name] = match (true) {
                $value === null => null,
                $function === 'count' => (int) $value,
                $function === 'avg', ($field['valueType'] ?? null) === 'float' => round((float) $value, 4),
                default => (int) $value,
            };
        }

        return $summaries;
    }

    /** @return array<string, mixed> */
    public function show(ResourceDescriptor $resource, string $id): array
    {
        $fields = $resource->showFields();
        $query = $this->baseQuery($resource, $fields)
            ->where($resource->primaryKeyColumn(), '=', $this->identifier($resource, $id));
        $row = $query->first();

        if (!is_array($row)) {
            throw RecordNotFoundException::of($resource->slug(), $id);
        }

        /** @var array<string, mixed> $row */
        $relations = $this->loadRelations($resource, $fields, [$row]);
        $record = $this->serializer->serialize($resource, $row, $fields, $relations);
        $policy = $this->policy($resource);
        $this->gate->authorize($this->user, static fn (CurrentUserInterface $user): bool|AuthorizationResponse => $policy->view($user, $record));

        return $record;
    }

    /**
     * Several records by identifier, serialized as show() serializes one,
     * in one statement plus one per relation field. For policy decisions
     * about records the caller already names; no view check of its own.
     *
     * @param list<int|string> $ids
     * @return array<string, array<string, mixed>> identifier => record
     */
    public function records(ResourceDescriptor $resource, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $fields = $resource->showFields();
        $rows = $this->baseQuery($resource, $fields)
            ->whereIn($resource->primaryKeyColumn(), array_map(fn (int|string $id): int|string => $this->identifier($resource, (string) $id), $ids))
            ->get();
        /** @var list<array<string, mixed>> $rows */
        $relations = $this->loadRelations($resource, $fields, $rows);
        $records = [];

        foreach ($rows as $row) {
            $record = $this->serializer->serialize($resource, $row, $fields, $relations);
            $records[(string) $record[$resource->primaryKeyField()]] = $record;
        }

        return $records;
    }

    /**
     * The per-record decisions a client needs to offer the right buttons.
     *
     * @param array<string, mixed> $record
     * @return array{update: bool, delete: bool, restore: bool, forceDelete: bool, actions: list<string>}
     */
    public function recordPermissions(ResourceDescriptor $resource, array $record): array
    {
        $policy = $this->policy($resource);
        $trashed = ($record['_trashed'] ?? false) === true;
        $soft = $resource->softDelete() !== null;

        return [
            'update' => !$trashed && $this->gate->allows($this->user, static fn (CurrentUserInterface $u): bool|AuthorizationResponse => $policy->update($u, $record)),
            'delete' => !$trashed && $this->gate->allows($this->user, static fn (CurrentUserInterface $u): bool|AuthorizationResponse => $policy->delete($u, $record)),
            'restore' => $soft && $trashed && $this->gate->allows($this->user, static fn (CurrentUserInterface $u): bool|AuthorizationResponse => $policy->restore($u, $record)),
            'forceDelete' => $soft && $trashed && $this->gate->allows($this->user, static fn (CurrentUserInterface $u): bool|AuthorizationResponse => $policy->forceDelete($u, $record)),
            'actions' => $this->visibleActions($resource, $record),
        ];
    }

    /**
     * The actions this user may run — on one record when given, on the
     * resource otherwise — by name.
     *
     * @param array<string, mixed>|null $record
     * @return list<string>
     */
    public function visibleActions(ResourceDescriptor $resource, ?array $record): array
    {
        $policy = $this->policy($resource);
        $names = [];

        foreach ($resource->actions() as $action) {
            if ($record !== null ? !$action['showOnDetail'] : !$action['showOnIndex']) {
                continue;
            }

            if (!$this->gate->allows($this->user, static fn (CurrentUserInterface $u): bool|AuthorizationResponse => $policy->runAction($u, $action['name']))) {
                continue;
            }

            $instance = $this->scope->get($action['class']);

            if ($instance instanceof VectorAction && $instance->canRun($this->user, $record)) {
                $names[] = $action['name'];
            }
        }

        return $names;
    }

    /**
     * Options for a relation input: the target's records matching a
     * search on its title, or exactly the given identifiers. A dependent
     * choice list, given the form's value of the field it depends on in
     * `$depends`, searches only the records matching it; without one (a
     * list filter has no form) it searches them all. Resolving identifiers
     * is never narrowed, so a stored choice keeps its label.
     *
     * @param list<string> $ids
     * @param array<string, string> $depends field name => the form's current value, as query text
     * @return list<array{id: int|string, label: string}>
     */
    public function options(ResourceDescriptor $resource, string $fieldName, string $search, array $ids, int $limit = 20, array $depends = []): array
    {
        $field = $resource->field($fieldName);
        $relation = $field['relation'] ?? null;

        if ($field === null || $relation === null || !in_array($field['type'], ['belongsTo', 'manyToMany'], true)) {
            throw new InvalidListRequestException("\"{$fieldName}\" is not a relation input of {$resource->slug()}.");
        }

        $target = $this->resource($relation['resource']);
        $this->gate->authorize($this->user, $this->policy($target)->viewAny(...));

        $query = $this->query($target)->table($target->table())
            ->select($target->primaryKeyColumn(), $target->titleColumn())
            ->orderBy($target->titleColumn(), 'ASC')
            ->limit(min($limit, 100));

        $dependsOn = $relation['dependsOn'] ?? null;
        $raw = $dependsOn === null ? '' : $depends[$dependsOn['field']] ?? '';

        if ($dependsOn !== null && $raw !== '' && $ids === []) {
            try {
                $query->where($dependsOn['column'], '=', $this->converter->fromQuery($resource->fields()[$dependsOn['field']], $raw));
            } catch (InputViolation $e) {
                throw new InvalidListRequestException("\"{$dependsOn['field']}\" {$e->violation->message}");
            }
        }

        if ($ids !== []) {
            $query->whereIn($target->primaryKeyColumn(), array_map(
                static fn (string $id): int|string => $target->primaryKeyType() === 'int' ? (int) $id : $id,
                array_slice($ids, 0, 100),
            ));
        } elseif ($search !== '') {
            $query->whereRaw(Sql::like($this->dialect($target), $target->titleColumn()), ['%' . addcslashes($search, '%_\\') . '%']);
        }

        $options = [];

        foreach ($query->get() as $row) {
            $options[] = [
                'id' => $target->primaryKeyType() === 'int' ? (int) $row[$target->primaryKeyColumn()] : (string) $row[$target->primaryKeyColumn()],
                'label' => (string) $row[$target->titleColumn()],
            ];
        }

        return $options;
    }

    /**
     * Whether a dependent choice is one the options would offer: the
     * target record `$id` has `$value` where the relation depends on it.
     * No policy is asked — this validates a write, not a listing.
     *
     * @param array<string, mixed> $field a belongsTo field with relation.dependsOn
     */
    public function dependentChoiceAllowed(array $field, mixed $id, mixed $value): bool
    {
        $target = $this->resource($field['relation']['resource']);
        return $this->query($target)->table($target->table())
            ->select($target->primaryKeyColumn())
            ->where($target->primaryKeyColumn(), '=', $id)
            ->where($field['relation']['dependsOn']['column'], '=', $value)
            ->limit(1)->get() !== [];
    }

    /**
     * The rows a repeater edits: every child of one record, as the child
     * resource shows a record, oldest first, up to the repeater's maximum.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(ResourceDescriptor $resource, string $id, string $fieldName): array
    {
        $field = $resource->field($fieldName);
        $repeater = $field['repeater'] ?? null;

        if ($field === null || $repeater === null) {
            throw new InvalidListRequestException("\"{$fieldName}\" is not a repeater of {$resource->slug()}.");
        }

        $this->show($resource, $id); // exists and is viewable
        $child = $this->resource($field['relation']['resource']);
        $this->gate->authorize($this->user, $this->policy($child)->viewAny(...));
        $keys = array_column($this->query($child)->table($child->table())
            ->select($child->primaryKeyColumn())
            ->where($field['relation']['foreignKey'], '=', $this->identifier($resource, $id))
            ->orderBy($child->primaryKeyColumn(), 'ASC')
            ->limit($repeater['max'])
            ->get(), $child->primaryKeyColumn());
        $records = $this->records($child, array_map(strval(...), $keys));

        return array_values(array_filter(array_map(static fn (mixed $key): ?array => $records[(string) $key] ?? null, $keys)));
    }

    /**
     * The records a hasMany or manyToMany field of one record holds, as
     * a page of the target resource.
     *
     * @return array<string, mixed>
     */
    public function related(ResourceDescriptor $resource, string $id, string $fieldName, ListRequest $request): array
    {
        $field = $resource->field($fieldName);
        $relation = $field['relation'] ?? null;

        if ($field === null || $relation === null || !in_array($field['type'], ['hasMany', 'manyToMany'], true)) {
            throw new InvalidListRequestException("\"{$fieldName}\" is not a collection of {$resource->slug()}.");
        }

        $this->show($resource, $id); // exists and is viewable
        $target = $this->resource($relation['resource']);
        $key = $this->identifier($resource, $id);

        return $this->list($target, $request, function (Query $query) use ($resource, $field, $relation, $target, $key): void {
            if ($field['type'] === 'hasMany') {
                $query->where($relation['foreignKey'], '=', $key);
            } else {
                $query->whereIn($target->primaryKeyColumn(), $this->query($resource)->table($relation['joinTable'])
                    ->select($relation['inverseJoinColumn'])
                    ->where($relation['joinColumn'], '=', $key));
            }
        });
    }

    /** @param list<string>|null $fields null leaves the projection untouched */
    private function baseQuery(ResourceDescriptor $resource, ?array $fields): Query
    {
        $query = $this->query($resource)->table($resource->table());

        if ($fields !== null) {
            $columns = [$resource->primaryKeyColumn()];

            foreach ($fields as $name) {
                $column = $resource->columns()[$name] ?? null;

                if ($column !== null && !in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }

            $soft = $resource->softDelete();

            if ($soft !== null && !in_array($soft['column'], $columns, true)) {
                $columns[] = $soft['column'];
            }

            if (($version = $resource->versionColumn()) !== null && !in_array($version, $columns, true)) {
                $columns[] = $version;
            }

            $query->select(...$columns);
        }

        $instance = $this->scope->get($resource->resourceClass());

        return $instance instanceof VectorResource ? $instance->scope($query, $this->user) : $query;
    }

    private function identifier(ResourceDescriptor $resource, string $id): int|string
    {
        if ($resource->primaryKeyType() === 'int') {
            return preg_match('/^-?[0-9]{1,18}$/', $id) === 1 ? (int) $id : throw RecordNotFoundException::of($resource->slug(), $id);
        }

        return $id;
    }

    /**
     * One statement per relation field on the page, bounded by the page
     * size: belongsTo labels by a whereIn on the target's key, counts by
     * a grouped whereIn on the foreign or join column.
     *
     * @param list<string> $fields
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<int|string, mixed>>
     */
    private function loadRelations(ResourceDescriptor $resource, array $fields, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $keys = array_map(fn (array $row): int|string => $this->serializer->key($row, $resource), $rows);
        $relations = [];

        foreach ($fields as $name) {
            $field = $resource->field($name);
            $relation = $field['relation'] ?? null;

            if ($field === null || $relation === null) {
                continue;
            }

            $target = $this->resource($relation['resource']);

            $relations[$name] = match ($field['type']) {
                'belongsTo' => $this->labels($target, array_values(array_unique(array_filter(
                    array_column($rows, $field['column']),
                    static fn (mixed $v): bool => $v !== null,
                ))), $relation['carry'] ?? []),
                'hasOne' => $this->hasOne($target, $relation['foreignKey'], $keys),
                'hasMany' => $this->counts($target, $relation['targetTable'], $relation['foreignKey'], $keys),
                'manyToMany' => $this->counts($target, $relation['joinTable'], $relation['joinColumn'], $keys),
                default => [],
            };
        }

        return $relations;
    }

    /**
     * The title of each related record, and the columns the relation
     * carries with its value (a related currency), in one statement.
     *
     * @param list<mixed> $ids
     * @param array<string, string> $carry field name => the target's column
     * @return array<int|string, array<string, string|null>> id => ['label' => title, field => value…]
     */
    private function labels(ResourceDescriptor $target, array $ids, array $carry = []): array
    {
        if ($ids === []) {
            return [];
        }

        $labels = [];

        foreach ($this->query($target)->table($target->table())
            ->select(...array_values(array_unique([$target->primaryKeyColumn(), $target->titleColumn(), ...array_values($carry)])))
            ->whereIn($target->primaryKeyColumn(), $ids)
            ->get() as $row) {
            $value = ['label' => (string) $row[$target->titleColumn()]];

            foreach ($carry as $name => $column) {
                $value[$name] = $row[$column] === null ? null : (string) $row[$column];
            }

            $labels[$target->primaryKeyType() === 'int' ? (int) $row[$target->primaryKeyColumn()] : (string) $row[$target->primaryKeyColumn()]] = $value;
        }

        return $labels;
    }

    /**
     * @param list<int|string> $keys
     * @return array<int|string, array{id: int|string, label: string}>
     */
    private function hasOne(ResourceDescriptor $target, string $foreignKey, array $keys): array
    {
        $related = [];

        foreach ($this->query($target)->table($target->table())
            ->select($target->primaryKeyColumn(), $target->titleColumn(), $foreignKey)
            ->whereIn($foreignKey, $keys)
            ->get() as $row) {
            $owner = is_numeric($row[$foreignKey]) ? (int) $row[$foreignKey] : (string) $row[$foreignKey];
            $id = $target->primaryKeyType() === 'int' ? (int) $row[$target->primaryKeyColumn()] : (string) $row[$target->primaryKeyColumn()];
            $related[$owner] = ['id' => $id, 'label' => (string) $row[$target->titleColumn()]];
        }

        return $related;
    }

    /**
     * @param list<int|string> $keys
     * @return array<int|string, int>
     */
    private function counts(ResourceDescriptor $target, string $table, string $column, array $keys): array
    {
        $counts = [];

        foreach ($this->query($target)->table($table)
            ->select($column)
            ->selectRaw('COUNT(*) AS vector_count')
            ->whereIn($column, $keys)
            ->groupBy($column)
            ->get() as $row) {
            $counts[is_numeric($row[$column]) ? (int) $row[$column] : (string) $row[$column]] = (int) $row['vector_count'];
        }

        return $counts;
    }
}
