<?php

declare(strict_types=1);

namespace Kinetis\Vector\Query;

use Kinetis\Vector\Exception\InvalidListRequestException;
use Kinetis\Vector\Registry\ResourceDescriptor;

/**
 * What an aggregate computes, validated against the resource as this
 * user sees it: up to MAX_GROUPS group keys, MAX_METRICS metrics, one
 * sort and a limit. Every column here came from the descriptor; nothing
 * from the request survives as SQL.
 *
 * One eligibility rule decides every field a group, a metric or the sort
 * names: it must be a field of the descriptor this user sees, what
 * vector_describe lists, so a field the policy hides (`viewField`) is
 * unknown, that its list or record view shows (the list's own rule for
 * the columns it serializes, so a form-only field is refused), mapped to
 * a column, and of a type the use takes (GROUPABLE,
 * SUMMABLE, COMPARABLE). A computed, rich-text, key-value or collection
 * field is refused, as it is for a sort and a summary.
 *
 * A money metric (sum, avg, min, max) keeps currencies apart: priced in a
 * currency field of the same record, that field becomes a group key of
 * its own (`implicit`), so no two currencies are ever added; priced in a
 * fixed currency, the result says which. Priced in a related record's
 * currency, it is refused: telling those apart would need a join.
 *
 * @phpstan-type Group array{field: string, column: string, type: string, valueType: string, kind: 'value'|'relation'|'bucket', bucket: string|null, implicit: bool, target: string|null}
 * @phpstan-type Metric array{key: string, fn: string, field: string|null, column: string|null, type: string|null, valueType: string|null}
 * @phpstan-type Money array{unit: 'minor', currency: string|null}|array{unit: 'minor', currencyGroup: string}
 */
final readonly class AggregateRequest
{
    public const int MAX_GROUPS = 3;
    public const int MAX_METRICS = 6;
    public const int DEFAULT_LIMIT = 25;
    public const int MAX_LIMIT = 100;

    /** The calendar units a date or datetime group is bucketed by, in UTC. */
    public const array BUCKETS = ['day', 'week', 'month', 'quarter', 'year'];

    public const array FUNCTIONS = ['count', 'sum', 'avg', 'min', 'max'];

    /** The timezone a bucket's boundaries fall in: the one Vector stores and filters instants in. */
    public const string TIMEZONE = 'UTC';

    /** The field types a group key may be: a bounded set of values, a scalar, a date by bucket, or a belongsTo's key. */
    private const array GROUPABLE = ['select', 'country', 'currency', 'boolean', 'string', 'text', 'email', 'url', 'slug', 'color', 'number', 'date', 'datetime', 'belongsTo'];

    /** What sum and avg take. */
    private const array SUMMABLE = ['number', 'money'];

    /** What min and max take. */
    private const array COMPARABLE = ['number', 'money', 'date', 'datetime'];

    private const array DATES = ['date', 'datetime'];

    /**
     * @param list<Group> $groups
     * @param list<Metric> $metrics
     * @param array{key: string, order: 'asc'|'desc'} $sort
     * @param array<string, Money> $money metric key => how its amounts are written
     */
    private function __construct(
        public array $groups,
        public array $metrics,
        public array $sort,
        public int $limit,
        public array $money,
    ) {}

    /**
     * @param array<array-key, mixed> $groupBy a list of `{field, bucket?}`
     * @param array<array-key, mixed> $metrics a list of `{fn, field?}`
     * @param string $sort a metric key or group field, `-` first for descending; '' for the first metric, descending
     */
    public static function fromArguments(array $groupBy, array $metrics, string $sort, int $limit, ResourceDescriptor $resource): self
    {
        if (!array_is_list($groupBy) || count($groupBy) > self::MAX_GROUPS) {
            throw new InvalidListRequestException('groupBy must be a list of 0 to ' . self::MAX_GROUPS . ' entries {field, bucket?}.');
        }

        if (!array_is_list($metrics) || $metrics === [] || count($metrics) > self::MAX_METRICS) {
            throw new InvalidListRequestException('metrics must be a list of 1 to ' . self::MAX_METRICS . ' entries {fn, field?}.');
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidListRequestException('limit must be between 1 and ' . self::MAX_LIMIT . '.');
        }

        $groups = [];

        foreach ($groupBy as $entry) {
            $group = self::group($entry, $resource);

            if (in_array($group['field'], array_column($groups, 'field'), true)) {
                throw new InvalidListRequestException("groupBy names \"{$group['field']}\" twice.");
            }

            $groups[] = $group;
        }

        $planned = [];
        $money = [];

        foreach ($metrics as $entry) {
            $metric = self::metric($entry, $resource);

            if (in_array($metric['key'], array_column($planned, 'key'), true)) {
                throw new InvalidListRequestException("metrics ask for {$metric['key']} twice.");
            }

            $planned[] = $metric;

            if ($metric['type'] === 'money' && $metric['fn'] !== 'count') {
                [$money[$metric['key']], $groups] = self::currency($metric, $resource, $groups);
            }
        }

        return new self($groups, $planned, self::sort($sort, $groups, $planned), $limit, $money);
    }

    /**
     * Every field this aggregate reads: its group keys (a currency one
     * included) and its metrics' fields.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return array_values(array_unique([
            ...array_column($this->groups, 'field'),
            ...array_values(array_filter(array_column($this->metrics, 'field'), is_string(...))),
        ]));
    }

    /**
     * A field of $resource, as this user sees it, that $use may name.
     *
     * @return array<string, mixed>
     */
    public static function eligible(ResourceDescriptor $resource, mixed $name, string $use): array
    {
        if (!is_string($name) || $name === '') {
            throw new InvalidListRequestException("{$use} needs a field name.");
        }

        $field = $resource->field($name);

        if ($field === null) {
            throw new InvalidListRequestException("\"{$name}\" is not a field of {$resource->slug()}; use the fields vector_describe lists.");
        }

        if (!self::readable($resource, $name)) {
            throw new InvalidListRequestException("\"{$name}\" is not a listable field of {$resource->slug()}: neither its list nor its record view shows it.");
        }

        $type = (string) $field['type'];

        $refused = match ($type) {
            'computed' => 'is computed per record in PHP, which the database cannot group or aggregate',
            'richtext' => 'is a rich-text field, which cannot be grouped or aggregated',
            'keyValue' => 'is a key-value field, which cannot be grouped or aggregated',
            'json' => 'is a JSON field, which cannot be grouped or aggregated',
            'select', 'country' => ($field['multiple'] ?? false) === true ? 'is a multiple select, a list of values, which cannot be grouped or aggregated' : null,
            'booleanGroup' => 'is a flag group, several values, which cannot be grouped or aggregated',
            'tag' => 'is a tag field, a list of values, which cannot be grouped or aggregated',
            'repeater' => 'is a repeater, a list of rows, which cannot be grouped or aggregated',
            'hasOne', 'hasMany', 'manyToMany' => "is a {$type} collection, which cannot be grouped or aggregated",
            default => isset($field['column']) ? null : 'maps no column and cannot be grouped or aggregated',
        };

        if ($refused !== null) {
            throw new InvalidListRequestException("\"{$name}\" {$refused}.");
        }

        return $field;
    }

    /**
     * Whether a read path shows $name: the list's own rule for the columns
     * it may serialize, so an aggregate reveals no value a list or a record
     * would not.
     */
    private static function readable(ResourceDescriptor $resource, string $name): bool
    {
        return in_array($name, $resource->indexFields(), true) || in_array($name, $resource->showFields(), true);
    }

    /**
     * @return Group
     */
    private static function group(mixed $entry, ResourceDescriptor $resource): array
    {
        if (!is_array($entry) || array_diff(array_keys($entry), ['field', 'bucket']) !== []) {
            throw new InvalidListRequestException('each groupBy entry must be an object {field, bucket?}.');
        }

        $field = self::eligible($resource, $entry['field'] ?? null, 'groupBy');
        $name = (string) $field['name'];
        $type = (string) $field['type'];
        $bucket = $entry['bucket'] ?? null;
        $bucket = $bucket === '' ? null : $bucket;

        if (!in_array($type, self::GROUPABLE, true)) {
            throw new InvalidListRequestException("\"{$name}\" is a {$type} field, which cannot be a group key.");
        }

        if (in_array($type, self::DATES, true) && $bucket === null) {
            throw new InvalidListRequestException("\"{$name}\" is a {$type} field: group it by a bucket, one of " . implode(', ', self::BUCKETS) . '.');
        }

        if (!in_array($type, self::DATES, true) && $bucket !== null) {
            throw new InvalidListRequestException("\"{$name}\" is not a date or datetime field; bucket applies only to those.");
        }

        if ($bucket !== null && !in_array($bucket, self::BUCKETS, true)) {
            throw new InvalidListRequestException('bucket must be one of ' . implode(', ', self::BUCKETS) . '.');
        }

        return [
            'field' => $name,
            'column' => (string) $field['column'],
            'type' => $type,
            'valueType' => (string) ($field['valueType'] ?? 'string'),
            'kind' => match (true) {
                $bucket !== null => 'bucket',
                $type === 'belongsTo' => 'relation',
                default => 'value',
            },
            'bucket' => is_string($bucket) ? $bucket : null,
            'implicit' => false,
            'target' => $type === 'belongsTo' ? (string) $field['relation']['resource'] : null,
        ];
    }

    /**
     * @return Metric
     */
    private static function metric(mixed $entry, ResourceDescriptor $resource): array
    {
        if (!is_array($entry) || array_diff(array_keys($entry), ['fn', 'field']) !== []) {
            throw new InvalidListRequestException('each metrics entry must be an object {fn, field?}.');
        }

        $fn = $entry['fn'] ?? null;

        if (!is_string($fn) || !in_array($fn, self::FUNCTIONS, true)) {
            throw new InvalidListRequestException('a metric\'s fn must be one of ' . implode(', ', self::FUNCTIONS) . '.');
        }

        $named = $entry['field'] ?? null;

        if ($fn === 'count') {
            if ($named !== null && $named !== '') {
                throw new InvalidListRequestException('count counts records and takes no field.');
            }

            return ['key' => 'count', 'fn' => 'count', 'field' => null, 'column' => null, 'type' => null, 'valueType' => null];
        }

        $field = self::eligible($resource, $named, "{$fn}");
        $name = (string) $field['name'];
        $type = (string) $field['type'];
        $takes = in_array($fn, ['sum', 'avg'], true) ? self::SUMMABLE : self::COMPARABLE;

        if (!in_array($type, $takes, true)) {
            throw new InvalidListRequestException("{$fn} takes a " . implode(', ', $takes) . " field; \"{$name}\" is a {$type} field.");
        }

        return ['key' => "{$fn}_{$name}", 'fn' => $fn, 'field' => $name, 'column' => (string) $field['column'], 'type' => $type, 'valueType' => (string) ($field['valueType'] ?? 'int')];
    }

    /**
     * How a money metric's amounts are written, and the groups with its
     * currency field among them when each record carries its own.
     *
     * @param Metric $metric
     * @param list<Group> $groups
     * @return array{0: Money, 1: list<Group>}
     */
    private static function currency(array $metric, ResourceDescriptor $resource, array $groups): array
    {
        $field = $resource->field((string) $metric['field']) ?? [];
        $fixed = $field['props']['currency'] ?? null;
        $path = $field['props']['currencyField'] ?? null;

        if (is_string($fixed) && $fixed !== '') {
            return [['unit' => 'minor', 'currency' => $fixed], $groups];
        }

        if (!is_string($path) || $path === '') {
            return [['unit' => 'minor', 'currency' => null], $groups];
        }

        if (str_contains($path, '.')) {
            throw new InvalidListRequestException("\"{$metric['field']}\" is priced in a related record's currency; {$metric['fn']} cannot keep its currencies apart. Count it, or group by that relation and read the amounts with vector_query.");
        }

        $currency = $resource->field($path);

        if ($currency === null || !isset($currency['column']) || !self::readable($resource, $path)) {
            // the currency is one this user may not see, or no read path shows: the amounts cannot be told apart, nor added
            throw new InvalidListRequestException("\"{$metric['field']}\" cannot be aggregated by this account: its currency is not available.");
        }

        if (!in_array($path, array_column($groups, 'field'), true)) {
            $groups[] = [
                'field' => $path,
                'column' => (string) $currency['column'],
                'type' => (string) $currency['type'],
                'valueType' => (string) ($currency['valueType'] ?? 'string'),
                'kind' => 'value',
                'bucket' => null,
                'implicit' => true,
                'target' => null,
            ];
        }

        return [['unit' => 'minor', 'currencyGroup' => $path], $groups];
    }

    /**
     * @param list<Group> $groups
     * @param list<Metric> $metrics
     * @return array{key: string, order: 'asc'|'desc'}
     */
    private static function sort(string $sort, array $groups, array $metrics): array
    {
        $sort = trim($sort);

        if ($sort === '') {
            return ['key' => $metrics[0]['key'], 'order' => 'desc'];
        }

        $order = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');
        $keys = [...array_column($metrics, 'key'), ...array_column($groups, 'field')];

        if (!in_array($key, $keys, true)) {
            throw new InvalidListRequestException("sort must name one metric key or group field of this aggregate, \"-\" first for descending: " . implode(', ', $keys) . '.');
        }

        return ['key' => $key, 'order' => $order];
    }
}
