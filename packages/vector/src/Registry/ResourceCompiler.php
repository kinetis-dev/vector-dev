<?php

declare(strict_types=1);

namespace Kinetis\Vector\Registry;

use BackedEnum;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Vector\Actions\VectorAction;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Auth\PasswordHasherInterface;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\BelongsTo;
use Kinetis\Vector\Fields\BooleanGroup;
use Kinetis\Vector\Fields\Boolean;
use Kinetis\Vector\Fields\Computed;
use Kinetis\Vector\Fields\Currency;
use Kinetis\Vector\Fields\Date;
use Kinetis\Vector\Fields\DateRange;
use Kinetis\Vector\Fields\DateTime;
use Kinetis\Vector\Fields\Field;
use Kinetis\Vector\Fields\HasMany;
use Kinetis\Vector\Fields\HasOne;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Json;
use Kinetis\Vector\Fields\ManyToMany;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Fields\RelationField;
use Kinetis\Vector\Fields\Repeater;
use Kinetis\Vector\Fields\Select;
use Kinetis\Vector\Fields\Tag;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Support\Countries;
use Kinetis\Vector\Validation\DateBounds;
use Kinetis\Vector\VectorResource;
use Kinetis\Vector\Write\RecordInput;
use ReflectionClass;
use ReflectionProperty;

/**
 * Turns every #[Resource] class plus the ORM's compiled metadata into the
 * descriptor: plain arrays, validated once, with every column an admin
 * request may name resolved here so nothing from a request ever reaches
 * SQL as an identifier.
 *
 * @phpstan-import-type EntityMapping from MetadataRegistry
 * @phpstan-import-type PropertyMapping from MetadataRegistry
 */
final class ResourceCompiler
{
    /** @var array<class-string, EntityMapping> entity class => mapping */
    private array $entities = [];

    /** @var array<class-string<VectorResource>, array{attribute: Resource, slug: string}> */
    private array $resources = [];

    /** @var array<string, string> entity class => the slug a relation to it links to: its only resource, or the one marked relationTarget */
    private array $slugByEntity = [];

    /**
     * The operators a filterable field admits, by the shape of its
     * value; `null`/`nnull` are added for a nullable column. A request
     * naming any other operator is refused at request time.
     */
    private const array OPERATORS = [
        'string' => ['eq', 'ne', 'like', 'nlike', 'in', 'nin'],
        'number' => ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'between', 'in', 'nin'],
        'boolean' => ['eq'],
        'datetime' => ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'between'],
        'enum' => ['eq', 'ne', 'in', 'nin'],
        // a JSON list of values (a multiple select's options, tags): holding any, or all, of the given ones;
        // a flag group's flags among the given keys any, or all, of which are on
        'list' => ['any', 'all'],
        'belongsTo' => ['eq', 'ne', 'in', 'nin'],
        'id' => ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'between', 'in', 'nin'],
        // a rich-text document is filtered by presence only (null/nnull on a nullable column)
        'presence' => [],
    ];

    /** The field types a list cell may edit in place. */
    private const array INLINE_TYPES = ['string', 'email', 'url', 'slug', 'color', 'number', 'money', 'boolean', 'select', 'country', 'currency', 'date', 'datetime', 'belongsTo'];

    /** The field types a list may group by: a bounded set of values or a sortable scalar. */
    private const array GROUPABLE = ['select', 'country', 'currency', 'belongsTo', 'boolean', 'string', 'date', 'slug', 'email'];

    /**
     * Which OPERATORS row a field type uses, or null for a field that
     * cannot be filtered at all (collections).
     */
    public static function operatorShape(string $type): ?string
    {
        return match ($type) {
            'id' => 'id',
            'number', 'money' => 'number',
            'boolean' => 'boolean',
            'date', 'datetime' => 'datetime',
            'select', 'country', 'currency' => 'enum',
            'booleanGroup' => 'list',
            'belongsTo' => 'belongsTo',
            'richtext' => 'presence',
            'tag' => 'list',
            'hasOne', 'hasMany', 'manyToMany', 'repeater' => null,
            default => 'string',
        };
    }

    /**
     * @param array<string, mixed> $field a compiled field
     * @return list<string>
     */
    public static function operatorsFor(array $field): array
    {
        $shape = self::isList($field) ? 'list' : self::operatorShape($field['type']);

        if ($shape === null || $field['filterable'] !== true) {
            return [];
        }

        $operators = self::OPERATORS[$shape];

        if (($field['nullable'] ?? false) === true) {
            $operators = [...$operators, 'null', 'nnull'];
        }

        return $operators;
    }

    /**
     * Whether a compiled field holds a list of option values: a multiple select.
     *
     * @param array<string, mixed> $field
     */
    public static function isMultiple(array $field): bool
    {
        return self::isSelect($field) && ($field['multiple'] ?? false) === true;
    }

    /**
     * Whether a compiled field is a select: a Select, or a preset of one (Country).
     *
     * @param array<string, mixed> $field
     */
    public static function isSelect(array $field): bool
    {
        return in_array($field['type'], ['select', 'country'], true);
    }

    /**
     * Whether a compiled field is a flag group whose flags live in bool
     * columns of their own (BooleanGroup in columns mode).
     *
     * @param array<string, mixed> $field
     */
    public static function isFlagColumns(array $field): bool
    {
        return $field['type'] === 'booleanGroup' && isset($field['flags']);
    }

    /**
     * One flag of a columns-mode flag group, addressed as `group.key`, as
     * the boolean field a filter or a sort reads: its own column, `eq`
     * when the group is filterable, sortable when the group is.
     *
     * @param array<string, mixed> $group the compiled group
     * @return array<string, mixed>|null
     */
    public static function flagField(array $group, string $key): ?array
    {
        $column = $group['flags'][$key] ?? null;

        if (!is_string($column)) {
            return null;
        }

        $label = array_column($group['options'], 'label', 'value')[$key] ?? $key;

        return [
            'name' => "{$group['name']}.{$key}",
            'type' => 'boolean',
            'label' => "{$group['label']}: {$label}",
            'column' => $column,
            'valueType' => 'bool',
            'nullable' => false,
            'filterable' => $group['filterable'],
            'sortable' => $group['flagsSortable'] ?? false,
            'operators' => $group['filterable'] === true ? ['eq'] : [],
            'views' => [],
            'rules' => [],
        ];
    }

    /**
     * Whether a compiled field holds a JSON list of plain values, filtered
     * by `any` (a bare value) or `all` of them: a multiple select, or tags.
     *
     * @param array<string, mixed> $field
     */
    public static function isList(array $field): bool
    {
        return self::isMultiple($field) || $field['type'] === 'tag';
    }

    /**
     * Whether a compiled field is a relationship-mode repeater: rows that
     * are child records, written with the parent rather than as a value.
     *
     * @param array<string, mixed> $field
     */
    public static function isChildRepeater(array $field): bool
    {
        return $field['type'] === 'repeater' && ($field['repeater']['mode'] ?? null) === 'relationship';
    }

    /**
     * Whether a compiled field holds a collection rather than one value
     * of its own: an inverse or join relation, or a repeater's rows.
     *
     * @param array<string, mixed> $field
     */
    public static function isCollection(array $field): bool
    {
        return in_array($field['type'], ['hasOne', 'hasMany', 'manyToMany', 'repeater'], true);
    }

    /**
     * Every dependent choice list names another field of the same form
     * holding a single value; the published relation carries that
     * field's label, for the input's hint.
     *
     * @param array<string, array<string, mixed>> $fields field name => compiled field
     * @return array<string, array<string, mixed>>
     */
    public static function checkDependencies(array $fields, string $owner): array
    {
        foreach ($fields as $name => $field) {
            $depends = $field['relation']['dependsOn'] ?? null;

            if ($depends === null) {
                continue;
            }

            $other = $fields[$depends['field']] ?? null;

            if ($other === null || $depends['field'] === $name) {
                throw ResourceCompileException::field($owner, $name, "depends on {$depends['field']}, which is not another field of the form");
            }

            if (self::isCollection($other) || in_array($other['type'], ['json', 'keyValue', 'booleanGroup', 'dateRange', 'file', 'image', 'password'], true) || self::isList($other)) {
                throw ResourceCompileException::field($owner, $name, "depends on {$depends['field']}, a {$other['type']} field, which holds no single value to match");
            }

            $fields[$name]['relation']['dependsOn']['label'] = $other['label'];
        }

        return $fields;
    }

    /**
     * Every condition of every field names another field of the same
     * form. A collection (hasOne, hasMany, manyToMany, a repeater's rows)
     * or a list (a multiple select, tags) can only be tested for being
     * empty or not.
     *
     * @param array<string, array<string, mixed>> $fields field name => compiled field
     */
    public static function checkConditions(array $fields, string $owner): void
    {
        foreach ($fields as $name => $field) {
            foreach ($field['conditions'] ?? [] as $kind => $conditions) {
                foreach ($conditions as $condition) {
                    self::checkCondition($fields, $owner, $name, $kind, $condition);
                }
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $fields field name => compiled field
     * @param array<string, mixed> $condition
     */
    private static function checkCondition(array $fields, string $owner, string $name, string $kind, array $condition): void
    {
        $target = $fields[$condition['field']] ?? null;

        if ($target === null) {
            throw ResourceCompileException::field($owner, $name, "has a {$kind} condition on \"{$condition['field']}\", which is not a field of the same form");
        }

        if ($condition['field'] === $name) {
            throw ResourceCompileException::field($owner, $name, "has a {$kind} condition on itself");
        }

        if ($target['type'] === 'booleanGroup') {
            throw ResourceCompileException::field($owner, $name, "has a {$kind} condition on the flag group \"{$condition['field']}\", which holds several values a condition cannot compare");
        }

        if (self::isDateRange($target)) {
            throw ResourceCompileException::field($owner, $name, "has a {$kind} condition on the date range \"{$condition['field']}\", which holds two values a condition cannot compare");
        }

        if ((self::isCollection($target) || self::isList($target)) && !in_array($condition['op'], ['null', 'nnull'], true)) {
            throw ResourceCompileException::field($owner, $name, "has a {$kind} condition on the collection \"{$condition['field']}\"; only null and nnull apply");
        }
    }

    /**
     * Compiles a field that maps no entity property (an action's or a
     * settings group's input) inferring the value type from the field
     * type. A belongsTo resolves to a registered resource's slug. A
     * `Json` or `KeyValue` input keeps its JSON objects, as one on a
     * `stdClass` property does.
     *
     * @param array<string, string> $slugByClass resource class => slug
     * @return array<string, mixed>
     */
    public static function compileLoose(Field $field, array $slugByClass, string $owner): array
    {
        $array = $field->toArray();
        $type = $field->type();

        if (in_array($type, ['hasOne', 'hasMany', 'manyToMany', 'id', 'repeater', 'dateRange'], true)) {
            throw ResourceCompileException::field($owner, $field->name(), "is a {$type} field, which an action or settings group cannot ask for");
        }

        $required = array_any($array['rules'], static fn (array $rule): bool => str_ends_with($rule['constraint'], '\\NotBlank'));
        // a flag group is always the object of every key, false where none is ticked, never null
        $array['nullable'] = !$required && $type !== 'booleanGroup';
        $array['valueType'] = match ($type) {
            'number' => isset($array['props']['decimals']) && $array['props']['decimals'] > 0 ? 'float' : 'int',
            'money' => 'int',
            'boolean' => 'bool',
            'date', 'datetime' => 'timestamp',
            default => 'string',
        };

        if ($type === 'select' || $type === 'country') {
            $array = self::compileLooseSelect($owner, $array);
        }

        if ($type === 'booleanGroup') {
            // an action's or a settings group's flags: the object itself, wherever it is kept
            $array = self::checkFlagOptions($owner, $array);
            $array['storage'] = 'json';
        }

        if ($type === 'json' || $type === 'keyValue' || $type === 'booleanGroup' || $type === 'tag') {
            $array['valueType'] = 'json';
        }

        // no property to hold {} as []: a JSON input keeps every object, a key-value input is one
        if ($type === 'json' || $type === 'keyValue') {
            $array['jsonObjects'] = $type === 'keyValue' ? 'object' : 'objectOrList';
        }

        if ($field instanceof RelationField) {
            $target = $field->resource() ?? throw ResourceCompileException::field($owner, $field->name(), 'names no target resource');
            $slug = $slugByClass[$target] ?? throw ResourceCompileException::field($owner, $field->name(), "{$target} is not a registered resource");
            $array['relation']['resource'] = $slug;
            $array['valueType'] = 'int';
        }

        $array['operators'] = [];

        return $array;
    }

    /**
     * A select with no entity property: its value type follows its
     * options (the enum's backing type, int when every value is an int,
     * otherwise string), and a multiple one holds a list.
     *
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    private static function compileLooseSelect(string $owner, array $array): array
    {
        $values = array_column($array['options'], 'value');

        if ($values === []) {
            throw ResourceCompileException::field($owner, $array['name'], 'is a select with no options; call options()');
        }

        self::checkCountryCodes($owner, $array);

        if (self::isMultiple($array)) {
            // a list of backing values: the ORM stores no enum case in JSON
            $array['enum'] = null;
            $array['valueType'] = 'json';

            return self::checkSelectDefault($owner, $array);
        }

        $array['valueType'] = array_all($values, static fn (mixed $v): bool => is_int($v)) ? 'int' : 'string';

        return self::checkSelectDefault($owner, self::typedOptions($array));
    }

    /**
     * A country's options must be assigned ISO 3166-1 alpha-2 codes, in
     * upper case: what only() names is checked here, with the resource.
     *
     * @param array<string, mixed> $array
     */
    private static function checkCountryCodes(string $owner, array $array): void
    {
        if ($array['type'] !== 'country') {
            return;
        }

        foreach (array_column($array['options'], 'value') as $code) {
            if (!is_string($code) || Countries::name($code) === null) {
                throw ResourceCompileException::field($owner, $array['name'], "offers \"{$code}\", which is not an assigned ISO 3166-1 alpha-2 country code");
            }
        }
    }

    /**
     * A flag group lists its flags, and its default, if any, is an object
     * of some of those keys to true or false.
     *
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    private static function checkFlagOptions(string $owner, array $array): array
    {
        if ($array['options'] === []) {
            throw ResourceCompileException::field($owner, $array['name'], 'is a flag group with no options; call options()');
        }

        if (($array['hasDefault'] ?? false) !== true || $array['default'] === null) {
            return $array;
        }

        $keys = array_column($array['options'], 'value');
        $default = $array['default'];

        if (!is_array($default) || ($default !== [] && array_is_list($default))
            || array_any($default, static fn (mixed $on, int|string $key): bool => !is_bool($on) || !in_array($key, $keys, true))) {
            throw ResourceCompileException::field($owner, $array['name'], 'is a flag group, whose default is an object of some of its option keys to true or false');
        }

        return $array;
    }

    /**
     * A flag group in columns mode: each option key a non-null bool
     * property of the entity, its column the flag's; the group's own name
     * is no property. Its sortable() sorts by each flag (`group.key`), so
     * the group itself is not sortable.
     *
     * @param EntityMapping $entity
     * @param array<string, mixed> $array
     * @param array<string, PropertyMapping> $properties
     * @return array<string, mixed>
     */
    private static function compileFlagColumns(string $class, array $entity, array $array, array $properties): array
    {
        $name = $array['name'];

        if (isset($properties[$name])) {
            throw ResourceCompileException::field($class, $name, "is a flag group in columns mode, which maps one bool property per option, but \"{$name}\" is a property itself; call json() to keep the flags in it");
        }

        $array = self::checkFlagOptions($class, $array);
        $flags = [];
        $defaults = [];
        $reflection = new ReflectionClass($entity['class']);

        foreach (array_column($array['options'], 'value') as $key) {
            $property = $properties[$key] ?? null;

            if ($property === null || $property['type'] !== 'bool' || $property['target'] !== null || $property['nullable']) {
                throw ResourceCompileException::field($class, $name, "names the flag \"{$key}\", which is not a bool property of {$entity['class']}; in columns mode every option key is one, or call json()");
            }

            $flags[$key] = $property['column'];
            $declared = $reflection->getProperty($key);

            if ($defaults !== null && $declared->hasDefaultValue()) {
                $defaults[$key] = $declared->getDefaultValue() === true;
            } else {
                $defaults = null;
            }
        }

        // every flag's property starting with a value: so does the group, and a create may leave it out
        if (($array['hasDefault'] ?? false) !== true && $defaults !== null) {
            $array['hasDefault'] = true;
            $array['default'] = $defaults;
        }

        $array['flags'] = $flags;
        $array['nullable'] = false;
        $array['valueType'] = 'json';
        $array['flagsSortable'] = $array['sortable'];
        $array['sortable'] = false;

        return $array;
    }

    /**
     * Whether a compiled field is a date range: two properties, one value.
     *
     * @param array<string, mixed> $field
     */
    public static function isDateRange(array $field): bool
    {
        return $field['type'] === 'dateRange';
    }

    /**
     * The properties a field writes besides its own: a columns-mode flag
     * group's flags, a date range's two ends.
     *
     * @param array<string, mixed> $field
     * @return list<string>
     */
    public static function writtenProperties(array $field): array
    {
        if (self::isDateRange($field)) {
            return [$field['range']['from'], $field['range']['to']];
        }

        return array_map(strval(...), array_keys($field['flags'] ?? []));
    }

    /**
     * A date range: two properties of the entity, both `Kinetis\Orm\Date`
     * (a range of days) or both `DateTimeImmutable` (a range of instants),
     * nullable only when both are. The field's own name is no property;
     * its bounds rule is compiled once the kind is known.
     *
     * @param EntityMapping $entity
     * @param array<string, mixed> $array
     * @param array<string, PropertyMapping> $properties
     * @return array<string, mixed>
     */
    private static function compileDateRange(string $class, array $entity, DateRange $field, array $array, array $properties): array
    {
        $name = $array['name'];
        ['from' => $from, 'to' => $to] = $field->properties();

        if (isset($properties[$name])) {
            throw ResourceCompileException::field($class, $name, "is a date range, which maps two properties, but \"{$name}\" is a property itself; name the range otherwise");
        }

        if ($from === $to) {
            throw ResourceCompileException::field($class, $name, "is a date range from and to the same property \"{$from}\"");
        }

        $ends = [];

        foreach (['from' => $from, 'to' => $to] as $end => $property) {
            $mapped = $properties[$property] ?? null;

            if ($mapped === null || $mapped['target'] !== null || !in_array($mapped['type'], ['date', 'timestamp'], true)) {
                throw ResourceCompileException::field($class, $name, "is a date range whose {$end} is \"{$property}\", which is not a Kinetis\\Orm\\Date or DateTimeImmutable property of {$entity['class']}");
            }

            $ends[$end] = $mapped;
        }

        if ($ends['from']['type'] !== $ends['to']['type']) {
            throw ResourceCompileException::field($class, $name, "is a date range from \"{$from}\", a {$ends['from']['type']}, to \"{$to}\", a {$ends['to']['type']}; both ends are Kinetis\\Orm\\Date or both DateTimeImmutable");
        }

        if ($ends['from']['nullable'] !== $ends['to']['nullable']) {
            throw ResourceCompileException::field($class, $name, "is a date range from \"{$from}\" to \"{$to}\", of which only one is nullable; both ends are set or neither is, so both are nullable or neither");
        }

        $kind = $ends['from']['type'] === 'date' ? DateBounds::DATE : DateBounds::DATETIME;

        try {
            $rule = $field->boundsRule($kind);
        } catch (\InvalidArgumentException $e) {
            throw ResourceCompileException::field($class, $name, $e->getMessage());
        }

        if ($rule !== null) {
            $array['rules'][] = $rule;
        }

        $array['range'] = ['kind' => $kind, 'from' => $from, 'to' => $to];
        $array['rangeColumns'] = ['from' => $ends['from']['column'], 'to' => $ends['to']['column']];
        $array['nullable'] = $ends['from']['nullable'];
        $array['valueType'] = $ends['from']['type'];

        return $array;
    }

    /**
     * What a date range needs once the form is compiled: neither end is a
     * field of its own, nor written by another range or flag group.
     *
     * @param array<string, mixed> $field
     * @param array<string, array<string, mixed>> $fields
     */
    private static function checkDateRange(string $class, array $field, array $fields): void
    {
        $name = $field['name'];

        foreach (self::writtenProperties($field) as $property) {
            if (isset($fields[$property])) {
                throw ResourceCompileException::field($class, $name, "maps \"{$property}\", which is also a field of the resource; a range's ends are written by the range alone");
            }

            foreach ($fields as $other => $candidate) {
                if ($other !== $name && in_array($property, self::writtenProperties($candidate), true)) {
                    throw ResourceCompileException::field($class, $name, "maps \"{$property}\", which the field \"{$other}\" writes too; a property is written by one field alone");
                }
            }
        }
    }

    /**
     * What a flag group needs once the form is compiled: in JSON mode an
     * `array` property and no sort; in columns mode no flag that is also
     * a field of its own, since the group writes it.
     *
     * @param array<string, mixed> $field
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private static function compileBooleanGroup(string $class, array $field, array $fields): array
    {
        $name = $field['name'];

        if (self::isFlagColumns($field)) {
            foreach (array_keys($field['flags']) as $key) {
                if (isset($fields[$key])) {
                    throw ResourceCompileException::field($class, $name, "names the flag \"{$key}\", which is also a field of the resource; a flag is written by its group alone");
                }

                foreach ($fields as $other => $group) {
                    if ($other !== $name && self::isFlagColumns($group) && isset($group['flags'][$key])) {
                        throw ResourceCompileException::field($class, $name, "names the flag \"{$key}\", which the flag group \"{$other}\" names too; a flag is written by one group alone");
                    }
                }
            }

            return $field;
        }

        $valueType = $field['valueType'] ?? null;

        if ($valueType !== 'json') {
            throw ResourceCompileException::field($class, $name, "is a flag group stored as JSON, which needs an array or ?array property, not {$valueType}");
        }

        if ($field['sortable']) {
            throw ResourceCompileException::field($class, $name, 'is a flag group stored as JSON, which cannot be sorted; keep its flags in bool columns to sort by each');
        }

        return self::checkFlagOptions($class, $field);
    }

    /**
     * A single select's option values typed as its property stores them:
     * text for a string property, so a declared `404 => 'Not found'`
     * reads and writes as "404".
     *
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    private static function typedOptions(array $array): array
    {
        if ($array['valueType'] === 'string') {
            foreach ($array['options'] as $i => $option) {
                $array['options'][$i]['value'] = (string) $option['value'];
            }
        }

        return $array;
    }

    /**
     * A select's default must be one of its options (a list of them for a
     * multiple one), compared by text, and is kept as the matched option's
     * own typed value: a write converts strictly, so `default(404)` on
     * options stored as text must be sent as "404", and the other way round.
     *
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    private static function checkSelectDefault(string $owner, array $array): array
    {
        if (($array['hasDefault'] ?? false) !== true || $array['default'] === null) {
            return $array;
        }

        $known = [];

        foreach (array_column($array['options'], 'value') as $option) {
            $known[(string) $option] ??= $option;
        }

        $default = $array['default'] instanceof \BackedEnum ? $array['default']->value : $array['default'];
        $multiple = self::isMultiple($array);
        $values = $multiple ? $default : [$default];

        if (!is_array($values) || !array_is_list($values)) {
            throw ResourceCompileException::field($owner, $array['name'], 'is a multiple select, whose default is a list of option values');
        }

        $typed = [];

        foreach ($values as $value) {
            if (!is_scalar($value) || !array_key_exists((string) $value, $known)) {
                throw ResourceCompileException::field($owner, $array['name'], 'defaults to "' . (is_scalar($value) ? (string) $value : get_debug_type($value)) . '", which is not one of its options');
            }

            $typed[] = $known[(string) $value];
        }

        $array['default'] = $multiple ? $typed : $typed[0];

        return $array;
    }

    /**
     * @param list<class-string> $classes every class carrying #[Resource]
     */
    public function __construct(MetadataRegistry $metadata, array $classes)
    {
        foreach ($metadata->toArray()['entities'] as $entity) {
            $this->entities[$entity['class']] = $entity;
        }

        /** @var array<class-string, non-empty-list<class-string<VectorResource>>> $byEntity entity class => its resources, in discovery order */
        $byEntity = [];

        foreach ($classes as $class) {
            $attribute = (new ReflectionClass($class))->getAttributes(Resource::class)[0] ?? null;

            if ($attribute === null) {
                throw ResourceCompileException::resource($class, 'carries no #[Resource] attribute');
            }

            if (!is_subclass_of($class, VectorResource::class)) {
                throw ResourceCompileException::resource($class, 'does not extend ' . VectorResource::class);
            }

            $resource = $attribute->newInstance();
            $this->resources[$class] = ['attribute' => $resource, 'slug' => $this->slugOf($class, $resource)];
            $byEntity[$resource->entity][] = $class;
        }

        foreach ($byEntity as $entityClass => $over) {
            $this->slugByEntity[$entityClass] = $this->relationTargetSlug($entityClass, $over);
        }
    }

    /**
     * The resource's slug, declared or derived from its entity's table:
     * lower-case letters, digits and dashes, used by no other resource.
     */
    private function slugOf(string $class, Resource $resource): string
    {
        $entity = $this->entities[$resource->entity]
            ?? throw ResourceCompileException::resource($class, "{$resource->entity} is not a mapped entity");
        $slug = $resource->slug ?? str_replace('_', '-', $entity['table']);

        if (preg_match('/^[a-z][a-z0-9-]*$/', $slug) !== 1) {
            throw ResourceCompileException::resource($class, "slug \"{$slug}\" must be lower-case letters, digits and dashes");
        }

        foreach ($this->resources as $other => $registered) {
            if ($registered['slug'] === $slug) {
                throw ResourceCompileException::resource($class, "slug \"{$slug}\" is already used by {$other}");
            }
        }

        return $slug;
    }

    /**
     * A relation that names no resource of its own links to the
     * entity's only resource, or to the one marked relationTarget.
     *
     * @param non-empty-list<class-string<VectorResource>> $over the entity's resources, in discovery order
     */
    private function relationTargetSlug(string $entityClass, array $over): string
    {
        $marked = array_values(array_filter($over, fn (string $class): bool => $this->resources[$class]['attribute']->relationTarget));

        if (count($over) > 1 && count($marked) !== 1) {
            throw new ResourceCompileException(sprintf(
                '%s has %d resources (%s), and %s; mark exactly one #[Resource(relationTarget: true)] for the relations to it to link to.',
                $entityClass,
                count($over),
                implode(', ', $over),
                $marked === [] ? 'none is the relation target' : 'more than one is the relation target (' . implode(', ', $marked) . ')',
            ));
        }

        return $this->resources[$marked[0] ?? $over[0]]['slug'];
    }

    /**
     * @return array<string, array<string, mixed>> slug => compiled resource
     */
    public function compile(): array
    {
        $compiled = [];

        foreach ($this->resources as $class => ['attribute' => $attribute, 'slug' => $slug]) {
            $compiled[$slug] = $this->compileResource($class, $attribute, $slug);
        }

        ksort($compiled, SORT_STRING);

        return self::linkSearchRelations(self::linkCarried(self::linkRepeaters($compiled)));
    }

    /**
     * A searchable belongsTo matches the related record's title, so the
     * target resource's title field must be a string column.
     *
     * @param array<string, array<string, mixed>> $compiled
     * @return array<string, array<string, mixed>>
     */
    private static function linkSearchRelations(array $compiled): array
    {
        foreach ($compiled as $resource) {
            foreach ($resource['searchRelations'] ?? [] as $name => $relation) {
                $target = $compiled[$relation['resource']];
                $title = array_column($target['fields'], null, 'name')[$target['titleField']];

                if (($title['valueType'] ?? null) !== 'string') {
                    throw ResourceCompileException::field($resource['class'], $name, "is searchable, but the title field \"{$target['titleField']}\" of {$target['slug']} is not a string column");
                }
            }
        }

        return $compiled;
    }

    /**
     * A money field priced in a related record's currency
     * (`currencyField('order.currency')`) has that relation carry the
     * currency with its value (`{id, label, currency}`) so a list or a
     * record shows it without a request of its own. The related field must
     * be a text column of the target resource.
     *
     * @param array<string, array<string, mixed>> $compiled
     * @return array<string, array<string, mixed>>
     */
    private static function linkCarried(array $compiled): array
    {
        foreach ($compiled as $slug => $resource) {
            $positions = array_flip(array_column($resource['fields'], 'name'));

            foreach ($resource['fields'] as $field) {
                $path = $field['props']['currencyField'] ?? null;

                if (!is_string($path) || !str_contains($path, '.')) {
                    continue;
                }

                [$relation, $name] = explode('.', $path, 2);
                $index = $positions[$relation];
                $target = $compiled[$resource['fields'][$index]['relation']['resource']];
                $carried = array_values(array_filter($target['fields'], static fn (array $f): bool => $f['name'] === $name))[0] ?? null;

                if ($carried === null || !isset($carried['column']) || ($carried['valueType'] ?? null) !== 'string') {
                    throw ResourceCompileException::field($resource['class'], $field['name'], "currencyField \"{$path}\" names no text column of {$target['slug']}");
                }

                $compiled[$slug]['fields'][$index]['relation']['carry'][$name] = $carried['column'];
            }
        }

        return $compiled;
    }

    /**
     * A relationship-mode repeater's rows are its child resource's
     * fields, known only once every resource is compiled: the ones a row
     * may write (or those the repeater names) never the field pointing
     * back at the parent nor the one orderBy() fills. A child field
     * required to create a row must be among them, and a soft-deleting
     * child cannot be one, since leaving a row out deletes it.
     *
     * @param array<string, array<string, mixed>> $compiled
     * @return array<string, array<string, mixed>>
     */
    private static function linkRepeaters(array $compiled): array
    {
        foreach ($compiled as $slug => $resource) {
            foreach ($resource['fields'] as $index => $field) {
                if (!self::isChildRepeater($field)) {
                    continue;
                }

                $repeater = $field['repeater'];

                $compiled[$slug]['fields'][$index]['repeater'] = self::linkRepeater($resource['class'], $field, $repeater, $compiled[$field['relation']['resource']]);
            }
        }

        return $compiled;
    }

    /**
     * @param array<string, mixed> $field the repeater field
     * @param array<string, mixed> $repeater its declaration
     * @param array<string, mixed> $child the compiled child resource
     * @return array<string, mixed>
     */
    private static function linkRepeater(string $owner, array $field, array $repeater, array $child): array
    {
        $inverse = $field['relation']['inverse'];
        $order = $repeater['orderBy'];
        $writable = self::repeaterWritable($child, $inverse, $order);

        foreach ($repeater['fields'] ?? [] as $name) {
            $candidate = array_column($child['fields'], null, 'name')[$name] ?? null;

            if ($candidate !== null && $candidate['type'] === 'repeater') {
                throw ResourceCompileException::field($owner, $field['name'], "names \"{$name}\", a repeater of {$child['labelSingular']}; rows never hold another repeater");
            }

            if ($name === $order) {
                throw ResourceCompileException::field($owner, $field['name'], "names \"{$name}\", the property orderBy() fills with each row's position");
            }

            if (!in_array($name, $writable, true)) {
                throw ResourceCompileException::field($owner, $field['name'], "names \"{$name}\", which is not a writable field of {$child['labelSingular']}");
            }
        }

        $fields = $repeater['fields'] ?? $writable;

        foreach ($child['fields'] as $candidate) {
            if ($candidate['name'] !== $inverse && $candidate['name'] !== $order && !in_array($candidate['name'], $fields, true) && RecordInput::requiredOnCreate($candidate)) {
                throw ResourceCompileException::field($owner, $field['name'], "leaves out \"{$candidate['name']}\", which a new {$child['labelSingular']} row requires");
            }
        }

        if ($child['softDelete'] !== null) {
            throw ResourceCompileException::field($owner, $field['name'], 'is a repeater over a soft-deleting resource; leaving a row out deletes it for good');
        }

        if (array_merge(...array_values($field['conditions'])) !== []) {
            throw ResourceCompileException::field($owner, $field['name'], 'is a repeater, which takes no visibleWhen, requiredWhen or disabledWhen');
        }

        return ['fields' => $fields] + $repeater;
    }

    /**
     * The child fields a repeater row may write: never the field pointing
     * back at the parent, the position orderBy() fills, an identifier or
     * a collection.
     *
     * @param array<string, mixed> $child the compiled child resource
     * @return list<string>
     */
    private static function repeaterWritable(array $child, string $inverse, ?string $order): array
    {
        $writable = [];

        foreach ($child['fields'] as $candidate) {
            if ($candidate['name'] !== $inverse && $candidate['name'] !== $order && $candidate['readonly'] !== true
                && array_intersect(['create', 'update'], $candidate['views']) !== []
                && !in_array($candidate['type'], ['id', 'hasOne', 'hasMany', 'manyToMany', 'repeater'], true)) {
                $writable[] = $candidate['name'];
            }
        }

        return $writable;
    }

    /**
     * @param class-string<VectorResource> $class
     * @return array<string, mixed>
     */
    private function compileResource(string $class, Resource $attribute, string $slug): array
    {
        $entity = $this->entities[$attribute->entity];
        $instance = new $class();
        // fields() may mix sections and tabs among the fields: the layout is kept apart
        ['fields' => $explicit, 'tree' => $layoutTree] = LayoutCompiler::split($instance->fields(), $class);
        $fields = $explicit === [] ? $this->derive($entity) : $explicit;
        $properties = [];

        foreach ($entity['properties'] as $property) {
            $properties[$property['name']] = $property;
        }

        ['fields' => $compiledFields, 'columns' => $columns, 'spans' => $spans] = $this->compileFields($class, $entity, $fields, $properties);
        $idField = $entity['id'];

        if (!isset($compiledFields[$idField])) {
            throw ResourceCompileException::resource($class, "the identifier field \"{$idField}\" must be administered");
        }

        $title = $attribute->title ?? $this->firstStringField($compiledFields) ?? $idField;

        if (!isset($compiledFields[$title]['column'])) {
            throw ResourceCompileException::resource($class, "title field \"{$title}\" maps no column");
        }

        $defaultSort = self::defaultSort($instance, $compiledFields, $idField, $class);
        $indexFields = self::inView($compiledFields, Field::VIEW_INDEX);
        $showFields = self::inView($compiledFields, Field::VIEW_SHOW);
        $entityProperties = self::entityProperties($entity);
        $timestamps = self::timestamps($instance, $entityProperties, $entity, $class);

        self::checkConditions($compiledFields, $class);
        self::checkComputedSources($compiledFields, $class);
        $compiledFields = self::checkDependencies($compiledFields, $class);
        $compiledFields = self::checkFieldTypes($class, $compiledFields, $properties);
        $creation = self::creation($entity, $entityProperties, $compiledFields, $timestamps);

        $ownedRelations = array_values(array_map(
            static fn (array $inverse): string => $inverse['name'],
            array_filter($entity['inverses'], static fn (array $inverse): bool => $inverse['owned']),
        ));

        $softDelete = self::softDelete($instance, $entity, $properties, $class);
        $fullText = self::fullText($instance, $compiledFields, $class);
        ['search' => $search, 'columns' => $searchColumns, 'relations' => $searchRelations] = self::searchPlan($compiledFields, $fullText);

        $groupable = array_keys(array_filter(
            $compiledFields,
            static fn (array $f): bool => $f['sortable'] === true && isset($f['column']) && in_array($f['type'], self::GROUPABLE, true),
        ));

        $summaries = self::summaryPlan($compiledFields);
        $actions = $this->compileActions($instance, $class);

        return [
            'slug' => $slug,
            'class' => $class,
            'entity' => $entity['class'],
            'connection' => $entity['connection'],
            'table' => $entity['table'],
            'label' => $attribute->label ?? Field::labelFrom($entity['table']),
            'labelSingular' => $attribute->labelSingular ?? Field::labelFrom((new ReflectionClass($entity['class']))->getShortName()),
            'group' => $attribute->group,
            'icon' => $attribute->icon,
            'primaryKey' => ['field' => $idField, 'column' => $columns[$idField], 'type' => $properties[$idField]['type']],
            'titleField' => $title,
            'version' => $entity['version'],
            'versionColumn' => $entity['version'] === null ? null : $this->columnOf($entity, $entity['version']),
            'fields' => array_values($compiledFields),
            'layout' => LayoutCompiler::compile($layoutTree, $compiledFields, $spans, $class),
            'columns' => $columns,
            'table_view' => [
                'columns' => $indexFields,
                'extra' => self::dependencies($indexFields, $compiledFields, $class),
                'defaultSort' => $defaultSort,
                'perPage' => $instance->perPage(),
                'perPageOptions' => $instance->perPageOptions(),
                'maxPerPage' => $instance->maxPerPage(),
                'search' => $search,
                'groupable' => $groupable,
                'summaries' => array_keys($summaries),
                'exportLimit' => $instance->exportLimit(),
                'exportQueueLimit' => $instance->exportQueueLimit(),
                'bulkLimit' => $instance->bulkLimit(),
            ] + array_filter(['withoutCount' => $instance->withoutCount(), 'cursorPaging' => $instance->cursorPaging()]),
            'show_view' => ['fields' => $showFields, 'extra' => self::dependencies($showFields, $compiledFields, $class)],
            'policy' => $instance->policy(),
            'entityProperties' => $entityProperties,
            'timestamps' => $timestamps,
            'creation' => $creation,
            'ownedRelations' => $ownedRelations,
            'softDelete' => $softDelete,
            'searchColumns' => $searchColumns,
            'summaryPlan' => $summaries,
            'replicable' => $instance->replicable(),
            'globallySearchable' => $instance->globallySearchable() && $search !== [],
            'searchResult' => self::searchResult($instance, $compiledFields, $class),
            'badge' => self::badge($instance, $compiledFields, $class),
            'actions' => array_values($actions),
            'widgets' => WidgetCompiler::widgets($instance->widgets(), $class),
        ] + array_filter(['searchRelations' => $searchRelations, 'fullText' => $fullText, 'meta' => self::resourceMeta($attribute, $class)]);
    }

    /**
     * The resource's metadata bag, checked as Field::meta() checks a field's.
     *
     * @param class-string $class
     * @return array<string, mixed>
     */
    private static function resourceMeta(Resource $attribute, string $class): array
    {
        foreach ($attribute->meta as $key => $value) {
            try {
                Field::checkMeta($key, $value, '#[Resource]');
            } catch (\InvalidArgumentException $e) {
                throw ResourceCompileException::resource($class, $e->getMessage());
            }
        }

        return $attribute->meta;
    }

    /**
     * Every field the resource administers, compiled against the entity's
     * mapping, with the column each maps and the span each declared.
     *
     * @param class-string<VectorResource> $class
     * @param EntityMapping $entity
     * @param list<Field> $fields
     * @param array<string, PropertyMapping> $properties
     * @return array{fields: array<string, array<string, mixed>>, columns: array<string, mixed>, spans: array<string, int|string|null>}
     */
    private function compileFields(string $class, array $entity, array $fields, array $properties): array
    {
        $inverses = array_column($entity['inverses'], null, 'name');
        $joins = array_column($entity['joins'], null, 'name');
        $compiledFields = [];
        $columns = [];
        $spans = [];

        foreach ($fields as $field) {
            $name = $field->name();
            $spans[$name] = $field->layoutSpan();

            if (isset($compiledFields[$name])) {
                throw ResourceCompileException::field($class, $name, 'is declared twice');
            }

            $array = $this->mapField($class, $entity, $field, $properties, $inverses, $joins);
            self::checkCapabilities($class, $name, $array);
            $array['operators'] = self::operatorsFor($array);

            if (isset($array['column'])) {
                $columns[$name] = $array['column'];
            }

            // each flag of a columns-mode group sorts by its own column, named `group.key`
            foreach ($array['flags'] ?? [] as $key => $column) {
                $columns["{$name}.{$key}"] = $column;
            }

            $compiledFields[$name] = $array;
        }

        return ['fields' => $compiledFields, 'columns' => $columns, 'spans' => $spans];
    }

    /**
     * One field mapped onto what it stores: a computed value, a relation,
     * or a mapped property of the entity.
     *
     * @param class-string<VectorResource> $class
     * @param EntityMapping $entity
     * @param array<string, PropertyMapping> $properties
     * @param array<string, array<string, mixed>> $inverses
     * @param array<string, array<string, mixed>> $joins
     * @return array<string, mixed>
     */
    private function mapField(string $class, array $entity, Field $field, array $properties, array $inverses, array $joins): array
    {
        $name = $field->name();
        $array = $field->toArray();

        if ($field instanceof Computed) {
            return self::compileComputed($class, $array);
        }

        if ($field instanceof RelationField) {
            return $this->compileRelation($class, $field, $array, $properties, $inverses, $joins);
        }

        if ($field instanceof BooleanGroup && $array['storage'] === BooleanGroup::COLUMNS) {
            return self::compileFlagColumns($class, $entity, $array, $properties);
        }

        if ($field instanceof Repeater && $field->relationName() !== null) {
            return $this->compileChildRepeater($class, $entity, $field, $array, $inverses);
        }

        if ($field instanceof DateRange) {
            return self::compileDateRange($class, $entity, $field, $array, $properties);
        }

        $property = $properties[$name] ?? throw ResourceCompileException::field($class, $name, "is not a mapped property of {$entity['class']}");

        if ($property['target'] !== null) {
            throw ResourceCompileException::field($class, $name, 'is a #[BelongsTo] property; declare it with BelongsTo::to()');
        }

        // a select on an enum property with no options of its own offers the enum's cases
        if ($field instanceof Select && !$field->hasOptions() && $property['enum'] !== null) {
            $array = [...$array, 'options' => (clone $field)->of($property['enum'])->toArray()['options']];
        }

        $declaredEnum = $array['enum'] ?? null;
        $array['column'] = $property['column'];
        $array['nullable'] = $property['nullable'];
        $array['valueType'] = $property['type'];
        $array['enum'] = $property['enum'];

        // a JSON or key-value field on a stdClass or array|stdClass property: a JSON value that keeps its objects
        if (in_array($field->type(), ['json', 'keyValue'], true) && in_array($property['type'], ['jsonObject', 'jsonObjectOrList'], true)) {
            $array['valueType'] = 'json';
            $array['jsonObjects'] = $property['type'] === 'jsonObject' ? 'object' : 'objectOrList';
        }

        if ($field instanceof Select && $property['enum'] !== null && $declaredEnum !== null && $declaredEnum !== $property['enum']) {
            throw ResourceCompileException::field($class, $name, "takes its options from {$declaredEnum}, but the property holds {$property['enum']}");
        }

        if ($field->type() === 'datetime' && $property['type'] === 'date') {
            throw ResourceCompileException::field($class, $name, 'maps a date-only Kinetis\\Orm\\Date property; declare it with Date::make()');
        }

        if ($field instanceof Repeater) {
            return $this->compileRowFields($class, $field, $array);
        }

        return $array;
    }

    /**
     * A relationship-mode repeater: an owned #[HasMany] of the entity,
     * whose child resource lives on the same connection, since its rows
     * are saved with the parent in one transaction. orderBy() names an
     * int property of the child.
     *
     * @param class-string<VectorResource> $class
     * @param EntityMapping $entity
     * @param array<string, mixed> $array
     * @param array<string, array<string, mixed>> $inverses
     * @return array<string, mixed>
     */
    private function compileChildRepeater(string $class, array $entity, Repeater $field, array $array, array $inverses): array
    {
        $name = $field->name();
        $relation = (string) $field->relationName();
        $inverse = $inverses[$relation] ?? throw ResourceCompileException::field($class, $name, "names the relation \"{$relation}\", which is not a #[HasMany] property of {$entity['class']}");

        if ($inverse['kind'] !== 'hasMany') {
            throw ResourceCompileException::field($class, $name, "names the relation \"{$relation}\", a #[HasOne]; a repeater edits a #[HasMany]");
        }

        if (!$inverse['owned']) {
            throw ResourceCompileException::field($class, $name, 'is a repeater, which needs an owned #[HasMany]: the parent writes and deletes its rows');
        }

        $slug = $this->childSlug($class, $name, $field->childResource(), $inverse['target']);
        $target = $this->entities[$inverse['target']];

        if ($target['connection'] !== $entity['connection']) {
            throw ResourceCompileException::field($class, $name, "edits {$inverse['target']} on the \"{$target['connection']}\" connection, but {$entity['class']} lives on \"{$entity['connection']}\"; a repeater saves its rows with the parent in one transaction, so both must share a connection");
        }

        foreach ($field->rowFields() as $row) {
            if (!is_string($row)) {
                throw ResourceCompileException::field($class, $name, "declares the row field \"{$row->name()}\"; in relationship mode fields() names fields of the child resource");
            }
        }

        $order = $array['repeater']['orderBy'];

        if ($order !== null) {
            $property = array_values(array_filter($target['properties'], static fn (array $p): bool => $p['name'] === $order))[0] ?? null;

            if ($property === null || $property['target'] !== null || $property['type'] !== 'int') {
                throw ResourceCompileException::field($class, $name, "orders its rows by \"{$order}\", which is not an int property of {$inverse['target']}");
            }

            $array['repeater']['orderColumn'] = $property['column'];
        }

        // no fields() call: every child field a row can write, known once the child is compiled
        $array['repeater']['fields'] = $field->declaresFields() ? $array['repeater']['fields'] : null;
        $array['nullable'] = $inverse['nullable'];
        $array['relation'] = [
            'kind' => 'hasMany',
            'resource' => $slug,
            'titleField' => null,
            'targetTable' => $target['table'],
            'targetKey' => $this->columnOf($target, $target['id']),
            'foreignKey' => $this->columnOf($target, $inverse['mappedBy']),
            'inverse' => $inverse['mappedBy'],
            'owned' => true,
            // the entity's property holding the rows, which may differ from the field's name
            'property' => $relation,
        ];

        return $array;
    }

    /**
     * The child resource a relationship-mode repeater edits: the one it
     * names, which must administer the child entity, or the one relations
     * to that entity link to.
     *
     * @param class-string<VectorResource> $class
     * @param class-string<VectorResource>|null $named
     */
    private function childSlug(string $class, string $name, ?string $named, string $childEntity): string
    {
        if ($named === null) {
            return $this->slugByEntity[$childEntity] ?? throw ResourceCompileException::field($class, $name, "edits {$childEntity}, which no resource administers");
        }

        $registered = $this->resources[$named] ?? throw ResourceCompileException::field($class, $name, "{$named} is not a registered resource");

        if ($registered['attribute']->entity !== $childEntity) {
            throw ResourceCompileException::field($class, $name, "names {$named}, which administers {$registered['attribute']->entity}, not {$childEntity}");
        }

        return $registered['slug'];
    }

    /**
     * A JSON-mode repeater's row fields, compiled as fields of no entity:
     * plain values only (no relation, file or nested repeater), each named
     * once, with no conditions. Its list keeps the order it is sent in,
     * so orderBy() has nothing to name.
     *
     * @param class-string<VectorResource> $class
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    private function compileRowFields(string $class, Repeater $field, array $array): array
    {
        $name = $field->name();

        if ($array['repeater']['orderBy'] !== null) {
            throw ResourceCompileException::field($class, $name, 'is a JSON repeater, whose rows keep the order they are sent in; orderBy() names a child property in relationship mode');
        }

        if ($field->rowFields() === []) {
            throw ResourceCompileException::field($class, $name, 'is a repeater with no row fields; call fields(), or relationship() to edit child records');
        }

        $slugByClass = array_map(static fn (array $r): string => $r['slug'], $this->resources);
        $compiled = [];

        foreach ($field->rowFields() as $row) {
            if (is_string($row)) {
                throw ResourceCompileException::field($class, $name, "names \"{$row}\"; a JSON repeater's fields() takes field definitions, and naming a child resource's fields needs relationship()");
            }

            if ($row instanceof Repeater) {
                throw ResourceCompileException::field($class, $name, "holds the repeater \"{$row->name()}\"; rows never hold another repeater");
            }

            if (!in_array($row->type(), Repeater::ROW_TYPES, true)) {
                throw ResourceCompileException::field($class, $name, "holds \"{$row->name()}\", a {$row->type()} field, which a JSON row cannot hold");
            }

            if (isset($compiled[$row->name()])) {
                throw ResourceCompileException::field($class, $name, "declares the row field \"{$row->name()}\" twice");
            }

            $sub = self::compileLoose($row, $slugByClass, $class);

            if (array_merge(...array_values($sub['conditions'])) !== []) {
                throw ResourceCompileException::field($class, $name, "gives the row field \"{$row->name()}\" a condition, which a row field does not take");
            }

            $compiled[$row->name()] = $sub;
        }

        $array['repeater']['rowFields'] = array_values($compiled);

        return $array;
    }

    /**
     * Sorting, searching and summarizing need a column of the right kind;
     * filtering needs a field that is not a collection.
     *
     * @param array<string, mixed> $array a compiled field
     */
    private static function checkCapabilities(string $class, string $name, array $array): void
    {
        if ($array['sortable'] === true && !isset($array['column']) && !self::isFlagColumns($array)) {
            throw ResourceCompileException::field($class, $name, 'maps no column and cannot be sortable');
        }

        // a searchable belongsTo matches the related record's title (linkSearchRelations)
        // a JSON column's own check (checkJsonColumn) names what it refuses
        if ($array['searchable'] === true && !in_array($array['type'], ['belongsTo', 'json', 'keyValue'], true) && ($array['valueType'] ?? null) !== 'string') {
            throw ResourceCompileException::field($class, $name, 'is not a string column and cannot be searchable');
        }

        if ($array['filterable'] === true && self::operatorShape($array['type']) === null) {
            throw ResourceCompileException::field($class, $name, 'is a collection and cannot be filterable');
        }

        if ($array['summary'] !== null && (!isset($array['column']) || !in_array($array['valueType'] ?? null, ['int', 'float'], true))) {
            throw ResourceCompileException::field($class, $name, 'is not a numeric column and cannot carry a summary');
        }
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @return list<array{field: string, order: string}>
     */
    private static function defaultSort(VectorResource $instance, array $fields, string $idField, string $class): array
    {
        $defaultSort = [];

        foreach ($instance->defaultSort() as $sort) {
            $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
            $name = ltrim($sort, '-');

            if (($fields[$name]['sortable'] ?? false) !== true && (self::flagOf($fields, $name)['sortable'] ?? false) !== true) {
                throw ResourceCompileException::resource($class, "default sort names \"{$name}\", which is not a sortable field");
            }

            $defaultSort[] = ['field' => $name, 'order' => $direction];
        }

        if ($defaultSort === []) {
            $defaultSort = [['field' => $idField, 'order' => 'asc']];
        }

        return $defaultSort;
    }

    /**
     * The flag `group.key` names among the fields, or null.
     *
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, mixed>|null
     */
    public static function flagOf(array $fields, string $name): ?array
    {
        [$group, $key] = str_contains($name, '.') ? explode('.', $name, 2) : [$name, ''];
        $field = $fields[$group] ?? null;

        return $field !== null && self::isFlagColumns($field) ? self::flagField($field, $key) : null;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @return list<string>
     */
    private static function inView(array $fields, string $view): array
    {
        return array_keys(array_filter(
            $fields,
            static fn (array $f): bool => in_array($view, $f['views'], true),
        ));
    }

    /**
     * A field another field's display depends on (a money field's
     * currency) is serialized with every view that shows the
     * dependent field, whether or not it is displayed itself.
     *
     * @param list<string> $visible
     * @param array<string, array<string, mixed>> $fields
     * @return list<string>
     */
    private static function dependencies(array $visible, array $fields, string $class): array
    {
        $extra = [];

        foreach ($visible as $name) {
            // a computed value is computed from its sources: they travel with it
            $needs = $fields[$name]['computed']['from'] ?? [];
            $currency = $fields[$name]['props']['currencyField'] ?? null;

            if ($currency !== null) {
                $needs[] = self::currencyDependency($fields, $name, $currency, $class);
            }

            foreach ($needs as $need) {
                if (!in_array($need, $visible, true) && !in_array($need, $extra, true)) {
                    $extra[] = $need;
                }
            }
        }

        return $extra;
    }

    /**
     * The field a money field's currencyField makes it depend on.
     *
     * @param array<string, array<string, mixed>> $fields
     */
    private static function currencyDependency(array $fields, string $name, string $currency, string $class): string
    {
        // a related record's currency travels with the relation's value: the relation is the dependency
        if (str_contains($currency, '.')) {
            $relation = explode('.', $currency, 2)[0];

            if (($fields[$relation]['type'] ?? null) !== 'belongsTo') {
                throw ResourceCompileException::field($class, $name, "currencyField \"{$currency}\" does not start with a belongsTo field");
            }

            return $relation;
        }

        if (!isset($fields[$currency]['column'])) {
            throw ResourceCompileException::field($class, $name, "currencyField \"{$currency}\" is not a column-mapped field");
        }

        return $currency;
    }

    /**
     * Every property of the entity, administered or not: what the
     * default writer must initialize on create, and what a delete
     * must load. hasDefaultValue() is PHP's own answer.
     *
     * @param EntityMapping $entity
     * @return array<string, array{kind: string, nullable: bool, hasDefault: bool, type: string|null, owned?: bool}>
     */
    private static function entityProperties(array $entity): array
    {
        $reflection = new ReflectionClass($entity['class']);
        $entityProperties = [];

        foreach ($entity['properties'] as $property) {
            $entityProperties[$property['name']] = [
                'kind' => $property['target'] === null ? 'column' : 'belongsTo',
                'nullable' => $property['nullable'],
                'hasDefault' => $reflection->getProperty($property['name'])->hasDefaultValue(),
                'type' => $property['type'],
            ];
        }

        foreach ($entity['inverses'] as $inverse) {
            $entityProperties[$inverse['name']] = ['kind' => $inverse['kind'], 'nullable' => $inverse['nullable'], 'hasDefault' => false, 'type' => null, 'owned' => $inverse['owned']];
        }

        foreach ($entity['joins'] as $join) {
            $entityProperties[$join['name']] = ['kind' => 'manyToMany', 'nullable' => false, 'hasDefault' => false, 'type' => null];
        }

        return $entityProperties;
    }

    /**
     * The timestamps Vector sets: declared, or the entity's createdAt and
     * updatedAt when they are DateTimeImmutable properties.
     *
     * @param array<string, array{kind: string, nullable: bool, hasDefault: bool, type: string|null, owned?: bool}> $entityProperties
     * @param EntityMapping $entity
     * @return array{created: string|null, updated: string|null}
     */
    private static function timestamps(VectorResource $instance, array $entityProperties, array $entity, string $class): array
    {
        $timestamps = $instance->timestamps() ?? [
            'created' => isset($entityProperties['createdAt']) && $entityProperties['createdAt']['type'] === 'timestamp' ? 'createdAt' : null,
            'updated' => isset($entityProperties['updatedAt']) && $entityProperties['updatedAt']['type'] === 'timestamp' ? 'updatedAt' : null,
        ];

        foreach (array_filter($timestamps) as $stamp) {
            if (($entityProperties[$stamp]['type'] ?? null) !== 'timestamp') {
                throw ResourceCompileException::resource($class, "timestamp property \"{$stamp}\" is not a DateTimeImmutable property of {$entity['class']}");
            }
        }

        return $timestamps;
    }

    /**
     * What each field type requires of its field once the whole form is
     * compiled; a rich-text field is compiled here.
     *
     * @param array<string, array<string, mixed>> $compiledFields
     * @param array<string, PropertyMapping> $properties
     * @return array<string, array<string, mixed>>
     */
    private static function checkFieldTypes(string $class, array $compiledFields, array $properties): array
    {
        foreach ($compiledFields as $name => $field) {
            if (in_array($name, ['or', 'and', 'trashed'], true) && $field['filterable'] === true) {
                throw ResourceCompileException::field($class, $name, 'is a word the filter grammar reserves, so it cannot be filterable');
            }

            if ($field['type'] === 'currency') {
                self::checkCurrency($class, $name, $field);
            }

            if ($field['type'] === 'password' && isset($field['props']['hasher'])) {
                self::checkHasher($class, $name, $field);
            }

            if ($field['type'] === 'richtext') {
                $compiledFields[$name] = self::compileRichText($class, $field, $compiledFields, $properties);
            }

            if ($field['type'] === 'keyValue' || $field['type'] === 'json') {
                self::checkJsonColumn($class, $name, $field);
            }

            if (self::isSelect($field)) {
                $compiledFields[$name] = self::compileSelect($class, $field);
            }

            if ($field['type'] === 'booleanGroup') {
                $compiledFields[$name] = self::compileBooleanGroup($class, $field, $compiledFields);
            }

            if (self::isDateRange($field)) {
                self::checkDateRange($class, $field, $compiledFields);
            }

            if ($field['type'] === 'tag') {
                self::checkTag($class, $name, $field);
            }

            if ($field['type'] === 'code' && ($field['valueType'] ?? null) !== 'string') {
                throw ResourceCompileException::field($class, $name, 'is a code field, which stores its text in a string property, not ' . ($field['valueType'] ?? 'none'));
            }

            if ($field['type'] === 'repeater') {
                self::checkRepeater($class, $name, $field);
            }

            self::checkInline($class, $name, $field);
        }

        return $compiledFields;
    }

    /** @param array<string, mixed> $field */
    private static function checkCurrency(string $class, string $name, array $field): void
    {
        $codes = array_column($field['options'], 'value');

        if (($field['valueType'] ?? null) !== 'string') {
            throw ResourceCompileException::field($class, $name, 'is a currency field, which stores its code in a string property');
        }

        if (($unknown = array_diff($codes, Currency::ISO_4217)) !== []) {
            throw ResourceCompileException::field($class, $name, 'allows "' . implode('", "', $unknown) . '", which ISO 4217 does not name');
        }

        if ($field['hasDefault'] && !in_array($field['default'], $codes, true)) {
            throw ResourceCompileException::field($class, $name, 'defaults to a currency it does not allow');
        }
    }

    /** @param array<string, mixed> $field */
    private static function checkHasher(string $class, string $name, array $field): void
    {
        $hasher = $field['props']['hasher'];

        if (!is_string($hasher) || !interface_exists($hasher) && !class_exists($hasher) || !is_a($hasher, PasswordHasherInterface::class, true)) {
            throw ResourceCompileException::field($class, $name, 'is hashed by ' . (is_string($hasher) ? $hasher : get_debug_type($hasher)) . ', which does not implement ' . PasswordHasherInterface::class);
        }

        if (($field['valueType'] ?? null) !== 'string') {
            throw ResourceCompileException::field($class, $name, 'is a hashed password, which stores its hash in a string property');
        }
    }

    /**
     * A key-value or JSON field lives in a JSON column, which the database
     * neither sorts, filters, searches nor sums for Vector, on a
     * `stdClass`, `?stdClass`, `array|stdClass` or `array|stdClass|null`
     * property, which keeps every object an object (compiled with
     * `jsonObjects`). An `array` or `?array` property is refused: PHP
     * holds `{}` and `[]` as one array, so an empty object, nested or
     * not, would be stored as `[]`.
     *
     * @param array<string, mixed> $field
     */
    private static function checkJsonColumn(string $class, string $name, array $field): void
    {
        $kind = $field['type'] === 'json' ? 'a JSON' : 'a key-value';
        $accepted = 'declare the property stdClass, ?stdClass, array|stdClass or array|stdClass|null';

        if (($field['valueType'] ?? null) !== 'json') {
            throw ResourceCompileException::field($class, $name, "is {$kind} field, which stores its value in a JSON column: {$accepted}");
        }

        if (!isset($field['jsonObjects'])) {
            throw ResourceCompileException::field($class, $name, "is {$kind} field on the array property \"{$name}\", which would store {} as []: {$accepted}");
        }

        if ($field['sortable'] || $field['filterable'] || $field['searchable'] || ($field['summary'] ?? null) !== null) {
            throw ResourceCompileException::field($class, $name, "is {$kind} field, which cannot be sorted, filtered, searched or summarized");
        }
    }

    /**
     * A select's storage must match its mode: one value in a string, int
     * or backed enum property; several in an `array` property (a JSON
     * column). Nothing is coerced either way. A multiple select is never
     * sorted, grouped, summarized or edited in a list cell.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function compileSelect(string $class, array $field): array
    {
        $name = $field['name'];
        $valueType = $field['valueType'] ?? null;
        $values = array_column($field['options'], 'value');

        if ($values === []) {
            throw ResourceCompileException::field($class, $name, 'is a select with no options; call options(), or map it to a backed enum property');
        }

        self::checkCountryCodes($class, $field);

        if (self::isMultiple($field)) {
            if ($valueType !== 'json') {
                throw ResourceCompileException::field($class, $name, "is a multiple select, which stores a JSON list: declare the property array or ?array, not {$valueType}");
            }

            if ($field['sortable'] || ($field['summary'] ?? null) !== null || ($field['inline'] ?? false) === true) {
                throw ResourceCompileException::field($class, $name, 'is a multiple select, which cannot be sorted, grouped, summarized or edited inline');
            }

            $field['enum'] = null;

            return self::checkSelectDefault($class, $field);
        }

        if ($valueType === 'json') {
            throw ResourceCompileException::field($class, $name, 'is a single select on an array property; call multiple() to store a list, or declare a string, int or backed enum property');
        }

        if (!in_array($valueType, ['string', 'int'], true)) {
            throw ResourceCompileException::field($class, $name, "is a select, which stores its value in a string, int or backed enum property, not {$valueType}");
        }

        if ($field['enum'] === null && $valueType === 'int' && !array_all($values, static fn (mixed $v): bool => is_int($v))) {
            throw ResourceCompileException::field($class, $name, 'stores an int, but not every option value is an integer');
        }

        if ($field['enum'] !== null) {
            $cases = array_map(static fn (\BackedEnum $case): string => (string) $case->value, $field['enum']::cases());

            foreach ($values as $value) {
                if (!in_array((string) $value, $cases, true)) {
                    throw ResourceCompileException::field($class, $name, "offers \"{$value}\", which is not a case of {$field['enum']}");
                }
            }
        }

        return self::checkSelectDefault($class, self::typedOptions($field));
    }

    /**
     * Tags live in a JSON column: an `array` or `?array` property, never
     * sorted, grouped, summarized, searched or edited in a list cell.
     *
     * @param array<string, mixed> $field
     */
    private static function checkTag(string $class, string $name, array $field): void
    {
        $valueType = $field['valueType'] ?? null;

        if ($valueType !== 'json') {
            throw ResourceCompileException::field($class, $name, "is a tag field, which stores a JSON list of strings: declare the property array or ?array, not {$valueType}");
        }

        if ($field['sortable'] || $field['searchable'] || ($field['summary'] ?? null) !== null || ($field['inline'] ?? false) === true) {
            throw ResourceCompileException::field($class, $name, 'is a tag field, which cannot be sorted, grouped, searched, summarized or edited inline');
        }

        $default = ($field['hasDefault'] ?? false) === true ? $field['default'] : null;

        if ($default !== null && (!is_array($default) || !array_is_list($default) || !array_all($default, static fn (mixed $v): bool => is_string($v)))) {
            throw ResourceCompileException::field($class, $name, 'is a tag field, whose default is a list of strings');
        }
    }

    /**
     * A repeater's rows are never sorted, grouped, filtered, searched,
     * summarized or edited in a list cell; a JSON-mode one lives in an
     * `array` or `?array` property.
     *
     * @param array<string, mixed> $field
     */
    private static function checkRepeater(string $class, string $name, array $field): void
    {
        if ($field['repeater']['mode'] === 'json' && ($field['valueType'] ?? null) !== 'json') {
            throw ResourceCompileException::field($class, $name, "is a JSON repeater, which stores its rows in a JSON column: declare the property array or ?array, not {$field['valueType']}");
        }

        if ($field['sortable'] || $field['filterable'] || $field['searchable'] || ($field['summary'] ?? null) !== null || ($field['inline'] ?? false) === true) {
            throw ResourceCompileException::field($class, $name, 'is a repeater, which cannot be sorted, grouped, filtered, searched, summarized or edited inline');
        }

        if ($field['repeater']['min'] > $field['repeater']['max']) {
            throw ResourceCompileException::field($class, $name, "is a repeater whose min() ({$field['repeater']['min']}) exceeds its max() ({$field['repeater']['max']})");
        }
    }

    /** @param array<string, mixed> $field */
    private static function checkInline(string $class, string $name, array $field): void
    {
        if (($field['inline'] ?? false) !== true) {
            return;
        }

        if (!in_array($field['type'], self::INLINE_TYPES, true)) {
            throw ResourceCompileException::field($class, $name, "is a {$field['type']} field, which cannot be edited inline");
        }

        if ($field['readonly'] === true || !in_array(Field::VIEW_UPDATE, $field['views'], true)) {
            throw ResourceCompileException::field($class, $name, 'is inline-editable but not editable on update');
        }
    }

    /**
     * Create is possible only when every property a new entity must
     * hold arrives from somewhere: a writable field, a timestamp
     * Vector sets, a default, or null.
     *
     * @param EntityMapping $entity
     * @param array<string, array{kind: string, nullable: bool, hasDefault: bool, type: string|null, owned?: bool}> $entityProperties
     * @param array<string, array<string, mixed>> $fields
     * @param array{created: string|null, updated: string|null} $timestamps
     * @return array{possible: bool, reason: string|null}
     */
    private static function creation(array $entity, array $entityProperties, array $fields, array $timestamps): array
    {
        $creates = static fn (array $field): bool => $field['readonly'] !== true && in_array(Field::VIEW_CREATE, $field['views'], true);
        // a flag group in columns mode writes each flag's property, a date range its two ends
        $flags = [];

        foreach ($fields as $field) {
            if ($creates($field)) {
                $flags = [...$flags, ...self::writtenProperties($field)];
            }
        }

        foreach ($entityProperties as $name => $property) {
            $writable = (isset($fields[$name]) && $creates($fields[$name])) || in_array($name, $flags, true);
            $supplied = $writable || $property['nullable'] || $property['hasDefault'] || in_array($name, $timestamps, true)
                || $name === $entity['id'] || in_array($property['kind'], ['hasOne', 'hasMany', 'manyToMany'], true);

            if (!$supplied) {
                return ['possible' => false, 'reason' => "the entity's \"{$name}\" property is required but not administered; declare a field for it or override create()"];
            }
        }

        return ['possible' => true, 'reason' => null];
    }

    /**
     * Soft deletion is a resource convention: the named nullable
     * timestamp property is set instead of the row being removed.
     *
     * @param EntityMapping $entity
     * @param array<string, PropertyMapping> $properties
     * @return array{field: string, column: string}|null
     */
    private static function softDelete(VectorResource $instance, array $entity, array $properties, string $class): ?array
    {
        $softProperty = $instance->softDeletes();

        if ($softProperty === null) {
            return null;
        }

        $mapped = $properties[$softProperty] ?? throw ResourceCompileException::resource($class, "softDeletes() names \"{$softProperty}\", which is not a mapped property of {$entity['class']}");

        if ($mapped['type'] !== 'timestamp' || !$mapped['nullable']) {
            throw ResourceCompileException::resource($class, "softDeletes() names \"{$softProperty}\", which is not a nullable DateTimeImmutable property");
        }

        return ['field' => $softProperty, 'column' => $mapped['column']];
    }

    /**
     * What a search query matches: the full-text columns, the titles of
     * searchable belongsTo relations, and the other searchable columns.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param array{columns: array<string, string>, config: string}|null $fullText
     * @return array{search: list<string>, columns: array<string, mixed>, relations: array<string, array{column: mixed, resource: mixed}>}
     */
    private static function searchPlan(array $fields, ?array $fullText): array
    {
        $plan = ['search' => [], 'columns' => [], 'relations' => []];

        foreach ($fields as $name => $field) {
            if (isset($fullText['columns'][$name])) {
                $plan['search'][] = $name;
            } elseif ($field['searchable'] === true && $field['type'] === 'belongsTo') {
                $plan['relations'][$name] = ['column' => $field['column'], 'resource' => $field['relation']['resource']];
                $plan['search'][] = $name;
            } elseif ($field['searchable'] === true && isset($field['column'])) {
                // a rich-text field is searched through its plain-text column
                $plan['columns'][$name] = $field['searchColumn'] ?? $field['column'];
                $plan['search'][] = $name;
            }
        }

        return $plan;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array{function: mixed, column: mixed}>
     */
    private static function summaryPlan(array $fields): array
    {
        $summaries = [];

        foreach ($fields as $name => $field) {
            if ($field['summary'] !== null) {
                $summaries[$name] = ['function' => $field['summary'], 'column' => $field['column']];
            }
        }

        return $summaries;
    }

    /**
     * @return array<string, array<string, mixed>> action name => compiled action
     */
    private function compileActions(VectorResource $instance, string $class): array
    {
        $actions = [];
        $slugByClass = array_map(static fn (array $r): string => $r['slug'], $this->resources);

        foreach ($instance->actions() as $actionClass) {
            if (!is_subclass_of($actionClass, VectorAction::class)) {
                throw ResourceCompileException::resource($class, "actions() names {$actionClass}, which is not a VectorAction");
            }

            $action = (new ReflectionClass($actionClass))->newInstanceWithoutConstructor(); // definitions need no dependencies
            $actionName = $action->name();

            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $actionName) !== 1) {
                throw ResourceCompileException::resource($class, "action {$actionClass} has the name \"{$actionName}\"; an action name is lower-case kebab-case");
            }

            if (isset($actions[$actionName])) {
                throw ResourceCompileException::resource($class, "two actions are named \"{$actionName}\"");
            }

            $actionFields = self::compileActionFields($action, $actionClass, $slugByClass);

            $actions[$actionName] = [
                'name' => $actionName,
                'class' => $actionClass,
                'label' => $action->label(),
                'icon' => $action->icon(),
                'confirmation' => $action->confirmation(),
                'destructive' => $action->destructive(),
                'standalone' => $action->standalone(),
                'queued' => $action->queued(),
                'chunkSize' => max(1, $action->chunkSize()),
                'showOnIndex' => $action->showOnIndex(),
                'showOnDetail' => $action->showOnDetail(),
                'fields' => array_values($actionFields),
            ];
        }

        return $actions;
    }

    /**
     * @param array<string, string> $slugByClass resource class => slug
     * @return array<string, array<string, mixed>> field name => compiled field
     */
    private static function compileActionFields(VectorAction $action, string $actionClass, array $slugByClass): array
    {
        $actionFields = [];

        foreach ($action->fields() as $field) {
            if (isset($actionFields[$field->name()])) {
                throw ResourceCompileException::field($actionClass, $field->name(), 'is declared twice');
            }

            $actionFields[$field->name()] = self::compileLoose($field, $slugByClass, $actionClass);
        }

        self::checkConditions($actionFields, $actionClass);

        return $actionFields;
    }

    /** The field types full-text search covers: text stored as it is shown. */
    private const array FULL_TEXT_TYPES = ['string', 'text', 'markdown', 'email', 'url', 'slug'];

    /**
     * The resource's full-text declaration: the columns its index covers,
     * each a searchable text field's, and the PostgreSQL text search
     * configuration, which reaches SQL as a literal and so must be a plain
     * name.
     *
     * @param array<string, array<string, mixed>> $fields
     * @return array{columns: array<string, string>, config: string}|null
     */
    private static function fullText(VectorResource $instance, array $fields, string $class): ?array
    {
        $declared = $instance->fullText();

        if ($declared === null) {
            return null;
        }

        if ($declared->fields === []) {
            throw ResourceCompileException::resource($class, 'fullText() names no field');
        }

        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $declared->config) !== 1) {
            throw ResourceCompileException::resource($class, "fullText() configures \"{$declared->config}\", which is not a PostgreSQL text search configuration name");
        }

        $columns = [];

        foreach ($declared->fields as $name) {
            $field = $fields[$name] ?? null;

            if ($field === null || ($field['valueType'] ?? null) !== 'string' || !in_array($field['type'], self::FULL_TEXT_TYPES, true)) {
                throw ResourceCompileException::resource($class, "fullText() names \"{$name}\", which is not a text field of the resource");
            }

            if ($field['searchable'] !== true) {
                throw ResourceCompileException::resource($class, "fullText() names \"{$name}\", which is not searchable");
            }

            if (isset($columns[$name])) {
                throw ResourceCompileException::resource($class, "fullText() names \"{$name}\" twice");
            }

            $columns[$name] = $field['column'];
        }

        return ['columns' => $columns, 'config' => $declared->config];
    }

    /** How a computed value is typed on the wire, by what it is shown as. */
    private const array COMPUTED_VALUE_TYPES = ['text' => 'string', 'number' => 'float', 'money' => 'int', 'boolean' => 'bool', 'date' => 'string', 'datetime' => 'string', 'select' => 'string'];

    /**
     * A computed field: a public instance method of the resource, shown
     * and never written, sorted, filtered, searched or summarized.
     *
     * @param class-string $class
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    private static function compileComputed(string $class, array $array): array
    {
        $name = $array['name'];
        $method = $array['computed']['method'] ?? throw ResourceCompileException::field($class, $name, 'is computed but names no method; call method()');

        // through ReflectionClass rather than `new ReflectionMethod`: a PHPStan rule this repository runs fails on that constructor's two signatures
        if (!method_exists($class, $method) || !(new \ReflectionClass($class))->getMethod($method)->isPublic() || (new \ReflectionClass($class))->getMethod($method)->isStatic()) {
            throw ResourceCompileException::field($class, $name, "is computed by {$method}(), which is not a public instance method of the resource");
        }

        if (array_intersect([Field::VIEW_CREATE, Field::VIEW_UPDATE], $array['views']) !== []) {
            throw ResourceCompileException::field($class, $name, 'is computed, so it cannot be on a form');
        }

        foreach (['sortable', 'filterable', 'searchable', 'inline'] as $flag) {
            if (($array[$flag] ?? false) === true) {
                throw ResourceCompileException::field($class, $name, "is computed per record in PHP, so it cannot be {$flag}");
            }
        }

        if ($array['summary'] !== null) {
            throw ResourceCompileException::field($class, $name, 'is computed per record in PHP; a summary needs a column the database can aggregate');
        }

        if ($array['computed']['as'] === 'select' && ($array['options'] ?? []) === []) {
            throw ResourceCompileException::field($class, $name, 'is computed as a select but lists no options; call options()');
        }

        $array['readonly'] = true;
        $array['nullable'] = true;
        $array['valueType'] = self::COMPUTED_VALUE_TYPES[$array['computed']['as']];

        return $array;
    }

    /**
     * A computed field reads other fields of the resource: never a
     * collection, never another computed field.
     *
     * @param array<string, array<string, mixed>> $fields
     */
    private static function checkComputedSources(array $fields, string $class): void
    {
        foreach ($fields as $name => $field) {
            foreach ($field['computed']['from'] ?? [] as $source) {
                $type = $fields[$source]['type'] ?? null;

                if ($type === null || in_array($type, ['computed', 'hasOne', 'hasMany', 'manyToMany', 'repeater'], true)) {
                    throw ResourceCompileException::field($class, $name, "is computed from \"{$source}\", which is not a field of the resource holding a value of its own");
                }
            }
        }
    }

    /**
     * A menu badge's filter names filterable fields with operators they
     * take, or `trashed`; its values are checked when it is counted.
     *
     * @param array<string, array<string, mixed>> $fields
     * @return array{filter: array<string, mixed>, color: string, label: string|null}|null
     */
    private static function badge(VectorResource $instance, array $fields, string $class): ?array
    {
        $badge = $instance->badge()?->toArray();

        if ($badge === null) {
            return null;
        }

        if (trim($badge['color']) === '') {
            throw new ResourceCompileException("{$class}::badge() has no color.");
        }

        self::checkBadgeFilter($badge['filter'], $fields, $class);

        return $badge;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, array<string, mixed>> $fields
     */
    private static function checkBadgeFilter(array $filter, array $fields, string $class): void
    {
        foreach ($filter as $name => $conditions) {
            if ($name === 'trashed') {
                continue;
            }

            if (in_array($name, ['or', 'and'], true) && is_array($conditions)) {
                self::checkBadgeGroup($conditions, $fields, $class);

                continue;
            }

            self::checkBadgeField($name, $conditions, $fields, $class);
        }
    }

    /**
     * @param array<mixed> $group the filters an `or` or `and` combines
     * @param array<string, array<string, mixed>> $fields
     */
    private static function checkBadgeGroup(array $group, array $fields, string $class): void
    {
        foreach ($group as $child) {
            self::checkBadgeFilter(is_array($child) ? $child : [], $fields, $class);
        }
    }

    /** @param array<string, array<string, mixed>> $fields */
    private static function checkBadgeField(string $name, mixed $conditions, array $fields, string $class): void
    {
        $field = $fields[$name] ?? null;

        if ($field === null || $field['filterable'] !== true) {
            throw new ResourceCompileException("{$class}::badge() filters on \"{$name}\", which is not a filterable field of the resource.");
        }

        foreach (array_keys(is_array($conditions) ? $conditions : []) as $operator) {
            if (!in_array($operator, $field['operators'], true)) {
                throw new ResourceCompileException("{$class}::badge() filters \"{$name}\" with \"{$operator}\", which that field does not take.");
            }
        }
    }

    /** The types a search subtitle shows as they are stored. */
    private const array SUBTITLE_TYPES = ['string', 'email', 'url', 'slug', 'text', 'number', 'select', 'country'];

    /**
     * What a global search result shows besides its title: a subtitle
     * field and an image field, each a stored column of the resource.
     *
     * @param array<string, array<string, mixed>> $fields
     * @return array{subtitle: string|null, image: string|null}
     */
    private static function searchResult(VectorResource $instance, array $fields, string $class): array
    {
        $subtitle = $instance->searchSubtitle();
        $image = $instance->searchImage();

        if ($subtitle !== null && (!in_array($fields[$subtitle]['type'] ?? null, self::SUBTITLE_TYPES, true) || self::isMultiple($fields[$subtitle]))) {
            throw new ResourceCompileException("{$class}::searchSubtitle() names \"{$subtitle}\", which is not a text, number or single select field of the resource.");
        }

        if ($image !== null && ($fields[$image]['type'] ?? null) !== 'image') {
            throw new ResourceCompileException("{$class}::searchImage() names \"{$image}\", which is not an image field of the resource.");
        }

        return ['subtitle' => $subtitle, 'image' => $image];
    }

    /**
     * A rich-text field: a string property holding the document; images
     * from a file or image field of the resource, mentions from one of its
     * relations; searchable only through a plain-text property it fills.
     *
     * @param array<string, mixed> $field
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, PropertyMapping> $properties
     * @return array<string, mixed>
     */
    private static function compileRichText(string $class, array $field, array $fields, array $properties): array
    {
        $name = $field['name'];
        $rich = $field['richtext'];

        if (($field['valueType'] ?? null) !== 'string') {
            throw ResourceCompileException::field($class, $name, 'is a rich-text field, which stores its document in a string property');
        }

        if ($field['sortable'] || $field['summary'] !== null) {
            throw ResourceCompileException::field($class, $name, 'is a rich-text field, which cannot be sorted or summarized');
        }

        if (in_array('image', $rich['features'], true) && ($rich['imageField'] === null || !in_array($fields[$rich['imageField']]['type'] ?? null, ['file', 'image'], true))) {
            throw ResourceCompileException::field($class, $name, 'allows images, which need imageField() naming a file or image field of the resource');
        }

        if (in_array('mention', $rich['features'], true) && ($rich['mentions'] === null || !in_array($fields[$rich['mentions']]['type'] ?? null, ['belongsTo', 'manyToMany'], true))) {
            throw ResourceCompileException::field($class, $name, 'allows mentions, which need mentions() naming a belongsTo or manyToMany field of the resource');
        }

        if ($rich['textField'] !== null) {
            $text = $properties[$rich['textField']] ?? null;

            if ($text === null || $text['type'] !== 'string' || $text['target'] !== null) {
                throw ResourceCompileException::field($class, $name, "names textField \"{$rich['textField']}\", which is not a string property of the entity");
            }

            $field['textColumn'] = $text['column'];
        }

        if ($field['searchable'] === true) {
            $field['searchColumn'] = $field['textColumn'] ?? throw ResourceCompileException::field($class, $name, 'is a rich-text field; searching it needs textField() naming a string property the writer fills with its plain text');
        }

        return $field;
    }

    /**
     * @param array<string, PropertyMapping> $properties
     * @param array<string, array<string, mixed>> $inverses
     * @param array<string, array<string, mixed>> $joins
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    private function compileRelation(string $class, RelationField $field, array $array, array $properties, array $inverses, array $joins): array
    {
        $name = $field->name();
        $targetResource = $field->resource() ?? throw ResourceCompileException::field($class, $name, 'names no target resource');
        $target = $this->resources[$targetResource]['attribute'] ?? throw ResourceCompileException::field($class, $name, "{$targetResource} is not a registered resource");
        $targetSlug = $this->resources[$targetResource]['slug'];
        $targetEntity = $this->entities[$target->entity];
        $relation = $array['relation'];
        $relation['resource'] = $targetSlug;
        $relation['targetTable'] = $targetEntity['table'];
        $relation['targetKey'] = $this->columnOf($targetEntity, $targetEntity['id']);

        switch ($field->type()) {
            case 'belongsTo':
                $property = $properties[$name] ?? throw ResourceCompileException::field($class, $name, 'is not a #[BelongsTo] property');

                if ($property['target'] !== $target->entity) {
                    throw ResourceCompileException::field($class, $name, "references {$property['target']}, not {$target->entity}");
                }

                $array['column'] = $property['column'];
                $array['nullable'] = $property['nullable'];
                $array['valueType'] = $property['type'];

                if (($relation['dependsOn'] ?? null) !== null) {
                    $on = $relation['dependsOn']['on'];
                    $mapped = array_filter($targetEntity['properties'], static fn (array $p): bool => $p['name'] === $on);

                    if ($mapped === []) {
                        throw ResourceCompileException::field($class, $name, "depends on {$on}, which {$target->entity} does not map");
                    }

                    $relation['dependsOn']['column'] = $this->columnOf($targetEntity, $on);
                }
                break;

            case 'hasOne':
            case 'hasMany':
                $inverse = $inverses[$name] ?? throw ResourceCompileException::field($class, $name, 'is not a #[HasOne]/#[HasMany] property');

                if ($inverse['target'] !== $target->entity) {
                    throw ResourceCompileException::field($class, $name, "references {$inverse['target']}, not {$target->entity}");
                }

                $relation['foreignKey'] = $this->columnOf($targetEntity, $inverse['mappedBy']);
                // the target's property pointing back, which a child form pre-fills with the parent
                $relation['inverse'] = $inverse['mappedBy'];
                $relation['owned'] = $inverse['owned'];
                $array['nullable'] = $inverse['nullable'];

                break;

            case 'manyToMany':
                $join = $joins[$name] ?? throw ResourceCompileException::field($class, $name, 'is not a #[ManyToMany] property');

                if ($join['target'] !== $target->entity) {
                    throw ResourceCompileException::field($class, $name, "references {$join['target']}, not {$target->entity}");
                }

                $relation['joinTable'] = $join['table'];
                $relation['joinColumn'] = $join['joinColumn'];
                $relation['inverseJoinColumn'] = $join['inverseJoinColumn'];
                $array['nullable'] = false;
                break;

            default:
                // another RelationField subclass: the target, its table and key above are the whole relation
                break;
        }

        $array['relation'] = $relation;

        return $array;
    }

    /**
     * Every mapped property becomes the field its type implies; a
     * relationship becomes a relation field only when its target has a
     * resource of its own.
     *
     * @param EntityMapping $entity
     * @return list<Field>
     */
    private function derive(array $entity): array
    {
        $fields = [];

        foreach ($entity['properties'] as $property) {
            $field = $this->deriveProperty($entity, $property);

            if ($field !== null) {
                $fields[] = $field;
            }
        }

        foreach ($entity['inverses'] as $inverse) {
            $slug = $this->slugByEntity[$inverse['target']] ?? null;

            if ($slug !== null) {
                $fields[] = $inverse['kind'] === 'hasOne'
                    ? HasOne::to($inverse['name'], $this->classOfSlug($slug))
                    : HasMany::to($inverse['name'], $this->classOfSlug($slug));
            }
        }

        foreach ($entity['joins'] as $join) {
            $slug = $this->slugByEntity[$join['target']] ?? null;

            if ($slug !== null) {
                $fields[] = ManyToMany::to($join['name'], $this->classOfSlug($slug))->hideFromForms();
            }
        }

        return $fields;
    }

    /**
     * The field a mapped property implies, or null for the version
     * property, a relationship whose target has no resource, and an
     * `array` property, which a `Json` field cannot map (checkJsonColumn()).
     *
     * @param EntityMapping $entity
     * @param PropertyMapping $property
     */
    private function deriveProperty(array $entity, array $property): ?Field
    {
        $name = $property['name'];

        return match (true) {
            $name === $entity['version'] => null,
            $name === $entity['id'] => Id::make($name),
            $property['target'] !== null => $this->deriveBelongsTo($name, $property['target']),
            $property['type'] === 'json' => null,
            default => self::deriveColumn($name, $property),
        };
    }

    private function deriveBelongsTo(string $name, string $target): ?Field
    {
        $slug = $this->slugByEntity[$target] ?? null;

        return $slug === null ? null : BelongsTo::to($name, $this->classOfSlug($slug))->sortable();
    }

    /** @param PropertyMapping $property */
    private static function deriveColumn(string $name, array $property): Field
    {
        $field = match (true) {
            $property['enum'] !== null => Select::make($name)->of($property['enum'])->filterable(),
            in_array($property['type'], ['jsonObject', 'jsonObjectOrList'], true) => Json::make($name)->hideFromIndex(),
            $property['type'] === 'string' => Text::make($name)->searchable(),
            $property['type'] === 'int' => Number::make($name),
            $property['type'] === 'float' => Number::make($name)->decimals(2),
            $property['type'] === 'bool' => Boolean::make($name)->filterable(),
            $property['type'] === 'date' => Date::make($name)->filterable(),
            default => DateTime::make($name)->filterable(),
        };

        if (!in_array($property['type'], ['jsonObject', 'jsonObjectOrList'], true)) {
            $field->sortable();
        }

        if (in_array($property['column'], ['created_at', 'updated_at', 'deleted_at'], true)) {
            $field->readonly()->hideFromForms();
        }

        return $field;
    }

    /** @return class-string<VectorResource> */
    private function classOfSlug(string $slug): string
    {
        foreach ($this->resources as $class => $registered) {
            if ($registered['slug'] === $slug) {
                return $class;
            }
        }

        throw new \LogicException("No resource has the slug {$slug}.");
    }

    /** @param EntityMapping $entity */
    private function columnOf(array $entity, string $property): string
    {
        foreach ($entity['properties'] as $mapped) {
            if ($mapped['name'] === $property) {
                return $mapped['column'];
            }
        }

        throw new \LogicException("{$entity['class']} maps no property {$property}.");
    }

    /** @param array<string, array<string, mixed>> $fields */
    private function firstStringField(array $fields): ?string
    {
        foreach ($fields as $name => $field) {
            if (($field['valueType'] ?? null) === 'string' && ($field['enum'] ?? null) === null && !in_array($field['type'], ['password', 'json', 'keyValue', 'select', 'country', 'booleanGroup', 'file', 'image', 'color', 'richtext', 'markdown', 'text', 'code'], true)) {
                return $name;
            }
        }

        return null;
    }
}
