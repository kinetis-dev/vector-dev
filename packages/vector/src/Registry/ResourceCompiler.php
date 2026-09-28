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
use Kinetis\Vector\Fields\Boolean;
use Kinetis\Vector\Fields\Computed;
use Kinetis\Vector\Fields\Currency;
use Kinetis\Vector\Fields\Date;
use Kinetis\Vector\Fields\DateTime;
use Kinetis\Vector\Fields\Enum;
use Kinetis\Vector\Fields\Field;
use Kinetis\Vector\Fields\HasMany;
use Kinetis\Vector\Fields\HasOne;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\ManyToMany;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Fields\RelationField;
use Kinetis\Vector\Fields\Text;
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

    /** @var array<string, string> entity class => slug */
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
        'belongsTo' => ['eq', 'ne', 'in', 'nin'],
        'id' => ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'between', 'in', 'nin'],
        // a rich-text document is filtered by presence only (null/nnull on a nullable column)
        'presence' => [],
    ];

    /** The field types a list cell may edit in place. */
    private const array INLINE_TYPES = ['string', 'email', 'url', 'slug', 'color', 'number', 'money', 'boolean', 'enum', 'currency', 'date', 'datetime', 'belongsTo'];

    /** The field types a list may group by: a bounded set of values or a sortable scalar. */
    private const array GROUPABLE = ['enum', 'currency', 'belongsTo', 'boolean', 'string', 'date', 'slug', 'email'];

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
            'enum', 'currency' => 'enum',
            'belongsTo' => 'belongsTo',
            'richtext' => 'presence',
            'hasOne', 'hasMany', 'manyToMany' => null,
            default => 'string',
        };
    }

    /**
     * @param array<string, mixed> $field a compiled field
     * @return list<string>
     */
    public static function operatorsFor(array $field): array
    {
        $shape = self::operatorShape($field['type']);

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

            if (in_array($other['type'], ['hasOne', 'hasMany', 'manyToMany', 'json', 'keyValue', 'file', 'image', 'password'], true)) {
                throw ResourceCompileException::field($owner, $name, "depends on {$depends['field']}, a {$other['type']} field, which holds no single value to match");
            }

            $fields[$name]['relation']['dependsOn']['label'] = $other['label'];
        }

        return $fields;
    }

    /**
     * Every condition of every field names another field of the same
     * form. A collection (hasOne, hasMany, manyToMany) can only be
     * tested for being empty or not.
     *
     * @param array<string, array<string, mixed>> $fields field name => compiled field
     */
    public static function checkConditions(array $fields, string $owner): void
    {
        foreach ($fields as $name => $field) {
            foreach ($field['conditions'] ?? [] as $kind => $conditions) {
                foreach ($conditions as $condition) {
                    $target = $fields[$condition['field']] ?? null;

                    if ($target === null) {
                        throw ResourceCompileException::field($owner, $name, "has a {$kind} condition on \"{$condition['field']}\", which is not a field of the same form");
                    }

                    if ($condition['field'] === $name) {
                        throw ResourceCompileException::field($owner, $name, "has a {$kind} condition on itself");
                    }

                    if (in_array($target['type'], ['hasOne', 'hasMany', 'manyToMany'], true) && !in_array($condition['op'], ['null', 'nnull'], true)) {
                        throw ResourceCompileException::field($owner, $name, "has a {$kind} condition on the collection \"{$condition['field']}\"; only null and nnull apply");
                    }
                }
            }
        }
    }

    /**
     * Compiles a field that maps no entity property — an action's or a
     * settings group's input — inferring the value type from the field
     * type. A belongsTo resolves to a registered resource's slug.
     *
     * @param array<string, string> $slugByClass resource class => slug
     * @return array<string, mixed>
     */
    public static function compileLoose(Field $field, array $slugByClass, string $owner): array
    {
        $array = $field->toArray();
        $type = $field->type();

        if (in_array($type, ['hasOne', 'hasMany', 'manyToMany', 'id'], true)) {
            throw ResourceCompileException::field($owner, $field->name(), "is a {$type} field, which an action or settings group cannot ask for");
        }

        $required = array_any($array['rules'], static fn (array $rule): bool => str_ends_with($rule['constraint'], '\\NotBlank'));
        $array['nullable'] = !$required;
        $array['valueType'] = match ($type) {
            'number' => isset($array['props']['decimals']) && $array['props']['decimals'] > 0 ? 'float' : 'int',
            'money' => 'int',
            'boolean' => 'bool',
            'date', 'datetime' => 'timestamp',
            default => 'string',
        };

        if ($type === 'enum') {
            $array['enum'] ?? throw ResourceCompileException::field($owner, $field->name(), 'is an enum field naming no backed enum; call of()');
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
     * @param list<class-string> $classes every class carrying #[Resource]
     */
    public function __construct(MetadataRegistry $metadata, array $classes)
    {
        foreach ($metadata->toArray()['entities'] as $entity) {
            $this->entities[$entity['class']] = $entity;
        }

        foreach ($classes as $class) {
            $attribute = (new ReflectionClass($class))->getAttributes(Resource::class)[0] ?? null;

            if ($attribute === null) {
                throw ResourceCompileException::resource($class, 'carries no #[Resource] attribute');
            }

            if (!is_subclass_of($class, VectorResource::class)) {
                throw ResourceCompileException::resource($class, 'does not extend ' . VectorResource::class);
            }

            $resource = $attribute->newInstance();
            $entity = $this->entities[$resource->entity]
                ?? throw ResourceCompileException::resource($class, "{$resource->entity} is not a mapped entity");
            $slug = $resource->slug ?? $entity['table'];

            if (preg_match('/^[a-z][a-z0-9-]*$/', $slug) !== 1) {
                throw ResourceCompileException::resource($class, "slug \"{$slug}\" must be lower-case letters, digits and dashes");
            }

            foreach ($this->resources as $other => $registered) {
                if ($registered['slug'] === $slug) {
                    throw ResourceCompileException::resource($class, "slug \"{$slug}\" is already used by {$other}");
                }
            }

            if (isset($this->slugByEntity[$resource->entity])) {
                throw ResourceCompileException::resource($class, "{$resource->entity} already has a resource");
            }

            $this->resources[$class] = ['attribute' => $resource, 'slug' => $slug];
            $this->slugByEntity[$resource->entity] = $slug;
        }
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

        return self::linkCarried(self::linkRepeaters($compiled));
    }

    /**
     * A money field priced in a related record's currency
     * (`currencyField('order.currency')`) has that relation carry the
     * currency with its value — `{id, label, currency}` — so a list or a
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
     * A repeater's rows are its child resource's fields, known only once
     * every resource is compiled: the ones a row may write — or those the
     * repeater names — never the field pointing back at the parent. A
     * child field required to create a row must be among them, and a
     * soft-deleting child cannot be one, since leaving a row out deletes it.
     *
     * @param array<string, array<string, mixed>> $compiled
     * @return array<string, array<string, mixed>>
     */
    private static function linkRepeaters(array $compiled): array
    {
        foreach ($compiled as $slug => $resource) {
            foreach ($resource['fields'] as $index => $field) {
                $repeater = $field['repeater'] ?? null;

                if ($repeater === null) {
                    continue;
                }

                $owner = $resource['class'];
                $child = $compiled[$field['relation']['resource']];
                $inverse = $field['relation']['inverse'];
                $writable = [];

                foreach ($child['fields'] as $candidate) {
                    if ($candidate['name'] !== $inverse && $candidate['readonly'] !== true
                        && array_intersect(['create', 'update'], $candidate['views']) !== []
                        && !in_array($candidate['type'], ['id', 'hasOne', 'hasMany', 'manyToMany'], true)) {
                        $writable[] = $candidate['name'];
                    }
                }

                foreach ($repeater['fields'] ?? [] as $name) {
                    if (!in_array($name, $writable, true)) {
                        throw ResourceCompileException::field($owner, $field['name'], "names \"{$name}\", which is not a field a {$child['labelSingular']} row can write");
                    }
                }

                $fields = $repeater['fields'] ?? $writable;

                foreach ($child['fields'] as $candidate) {
                    if ($candidate['name'] !== $inverse && !in_array($candidate['name'], $fields, true) && RecordInput::requiredOnCreate($candidate)) {
                        throw ResourceCompileException::field($owner, $field['name'], "leaves out \"{$candidate['name']}\", which a new {$child['labelSingular']} row requires");
                    }
                }

                if ($child['softDelete'] !== null) {
                    throw ResourceCompileException::field($owner, $field['name'], 'is a repeater over a soft-deleting resource; leaving a row out deletes it for good');
                }

                if (array_merge(...array_values($field['conditions'])) !== []) {
                    throw ResourceCompileException::field($owner, $field['name'], 'is a repeater, which takes no visibleWhen, requiredWhen or disabledWhen');
                }

                $compiled[$slug]['fields'][$index]['repeater'] = ['fields' => $fields, 'min' => $repeater['min'], 'max' => $repeater['max']];
            }
        }

        return $compiled;
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

            $array = $field->toArray();

            if ($field instanceof Computed) {
                $array = self::compileComputed($class, $array);
            } elseif ($field instanceof RelationField) {
                $array = $this->compileRelation($class, $field, $array, $properties, $inverses, $joins);
            } elseif (isset($properties[$name])) {
                $property = $properties[$name];

                if ($property['target'] !== null) {
                    throw ResourceCompileException::field($class, $name, 'is a #[BelongsTo] property; declare it with BelongsTo::to()');
                }

                $array['column'] = $property['column'];
                $array['nullable'] = $property['nullable'];
                $array['valueType'] = $property['type'];
                $array['enum'] = $property['enum'];

                if ($field->type() === 'datetime' && $property['type'] === 'date') {
                    throw ResourceCompileException::field($class, $name, 'maps a date-only Kinetis\\Orm\\Date property; declare it with Date::make()');
                }
            } else {
                throw ResourceCompileException::field($class, $name, "is not a mapped property of {$entity['class']}");
            }

            if ($array['sortable'] === true && !isset($array['column'])) {
                throw ResourceCompileException::field($class, $name, 'maps no column and cannot be sortable');
            }

            if ($array['searchable'] === true && ($array['valueType'] ?? null) !== 'string') {
                throw ResourceCompileException::field($class, $name, 'is not a string column and cannot be searchable');
            }

            if ($array['filterable'] === true && self::operatorShape($array['type']) === null) {
                throw ResourceCompileException::field($class, $name, 'is a collection and cannot be filterable');
            }

            if ($array['summary'] !== null && (!isset($array['column']) || !in_array($array['valueType'] ?? null, ['int', 'float'], true))) {
                throw ResourceCompileException::field($class, $name, 'is not a numeric column and cannot carry a summary');
            }

            $array['operators'] = self::operatorsFor($array);

            if (isset($array['column'])) {
                $columns[$name] = $array['column'];
            }

            $compiledFields[$name] = $array;
        }

        $idField = $entity['id'];

        if (!isset($compiledFields[$idField])) {
            throw ResourceCompileException::resource($class, "the identifier field \"{$idField}\" must be administered");
        }

        $title = $attribute->title ?? $this->firstStringField($compiledFields) ?? $idField;

        if (!isset($compiledFields[$title]['column'])) {
            throw ResourceCompileException::resource($class, "title field \"{$title}\" maps no column");
        }

        $defaultSort = [];

        foreach ($instance->defaultSort() as $sort) {
            $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
            $name = ltrim($sort, '-');

            if (($compiledFields[$name]['sortable'] ?? false) !== true) {
                throw ResourceCompileException::resource($class, "default sort names \"{$name}\", which is not a sortable field");
            }

            $defaultSort[] = ['field' => $name, 'order' => $direction];
        }

        if ($defaultSort === []) {
            $defaultSort = [['field' => $idField, 'order' => 'asc']];
        }

        $inView = static fn (string $view): array => array_keys(array_filter(
            $compiledFields,
            static fn (array $f): bool => in_array($view, $f['views'], true),
        ));

        // A field another field's display depends on — a money field's
        // currency — is serialized with every view that shows the
        // dependent field, whether or not it is displayed itself.
        $dependencies = static function (array $visible) use ($compiledFields, $class): array {
            $extra = [];

            foreach ($visible as $name) {
                $currency = $compiledFields[$name]['props']['currencyField'] ?? null;

                // a computed value is computed from its sources: they travel with it
                foreach ($compiledFields[$name]['computed']['from'] ?? [] as $source) {
                    if (!in_array($source, $visible, true) && !in_array($source, $extra, true)) {
                        $extra[] = $source;
                    }
                }

                if ($currency === null) {
                    continue;
                }

                // a related record's currency travels with the relation's value: the relation is the dependency
                if (str_contains($currency, '.')) {
                    $currency = explode('.', $currency, 2)[0];

                    if (($compiledFields[$currency]['type'] ?? null) !== 'belongsTo') {
                        throw ResourceCompileException::field($class, $name, "currencyField \"{$compiledFields[$name]['props']['currencyField']}\" does not start with a belongsTo field");
                    }
                } elseif (!isset($compiledFields[$currency]['column'])) {
                    throw ResourceCompileException::field($class, $name, "currencyField \"{$currency}\" is not a column-mapped field");
                }

                if (!in_array($currency, $visible, true) && !in_array($currency, $extra, true)) {
                    $extra[] = $currency;
                }
            }

            return $extra;
        };
        $indexFields = $inView(Field::VIEW_INDEX);
        $showFields = $inView(Field::VIEW_SHOW);

        // Every property of the entity, administered or not: what the
        // default writer must initialize on create, and what a delete
        // must load. hasDefaultValue() is PHP's own answer.
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

        $timestamps = $instance->timestamps() ?? [
            'created' => isset($entityProperties['createdAt']) && $entityProperties['createdAt']['type'] === 'timestamp' ? 'createdAt' : null,
            'updated' => isset($entityProperties['updatedAt']) && $entityProperties['updatedAt']['type'] === 'timestamp' ? 'updatedAt' : null,
        ];

        foreach (array_filter($timestamps) as $stamp) {
            if (($entityProperties[$stamp]['type'] ?? null) !== 'timestamp') {
                throw ResourceCompileException::resource($class, "timestamp property \"{$stamp}\" is not a DateTimeImmutable property of {$entity['class']}");
            }
        }

        self::checkConditions($compiledFields, $class);
        self::checkComputedSources($compiledFields, $class);
        $compiledFields = self::checkDependencies($compiledFields, $class);

        foreach ($compiledFields as $name => $field) {
            if (in_array($name, ['or', 'and', 'trashed'], true) && $field['filterable'] === true) {
                throw ResourceCompileException::field($class, $name, 'is a word the filter grammar reserves, so it cannot be filterable');
            }

            if ($field['type'] === 'currency') {
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

            if ($field['type'] === 'password' && isset($field['props']['hasher'])) {
                $hasher = $field['props']['hasher'];

                if (!is_string($hasher) || !interface_exists($hasher) && !class_exists($hasher) || !is_a($hasher, PasswordHasherInterface::class, true)) {
                    throw ResourceCompileException::field($class, $name, 'is hashed by ' . (is_string($hasher) ? $hasher : get_debug_type($hasher)) . ', which does not implement ' . PasswordHasherInterface::class);
                }

                if (($field['valueType'] ?? null) !== 'string') {
                    throw ResourceCompileException::field($class, $name, 'is a hashed password, which stores its hash in a string property');
                }
            }

            if ($field['type'] === 'richtext') {
                $compiledFields[$name] = self::compileRichText($class, $field, $compiledFields, $properties);
            }

            if ($field['type'] === 'keyValue') {
                if (($field['valueType'] ?? null) !== 'string') {
                    throw ResourceCompileException::field($class, $name, 'is a key-value field, which stores its JSON in a string property');
                }

                if ($field['sortable'] || $field['filterable'] || $field['searchable'] || ($field['summary'] ?? null) !== null) {
                    throw ResourceCompileException::field($class, $name, 'is a key-value field, which cannot be sorted, filtered, searched or summarized');
                }
            }

            if (($field['inline'] ?? false) !== true) {
                continue;
            }

            if (!in_array($field['type'], self::INLINE_TYPES, true)) {
                throw ResourceCompileException::field($class, $name, "is a {$field['type']} field, which cannot be edited inline");
            }

            if ($field['readonly'] === true || !in_array(Field::VIEW_UPDATE, $field['views'], true)) {
                throw ResourceCompileException::field($class, $name, 'is inline-editable but not editable on update');
            }
        }

        // Create is possible only when every property a new entity must
        // hold arrives from somewhere: a writable field, a timestamp
        // Vector sets, a default, or null.
        $creation = ['possible' => true, 'reason' => null];

        foreach ($entityProperties as $name => $property) {
            $writable = isset($compiledFields[$name])
                && $compiledFields[$name]['readonly'] !== true
                && in_array(Field::VIEW_CREATE, $compiledFields[$name]['views'], true);
            $supplied = $writable || $property['nullable'] || $property['hasDefault'] || in_array($name, $timestamps, true)
                || $name === $entity['id'] || in_array($property['kind'], ['hasOne', 'hasMany', 'manyToMany'], true);

            if (!$supplied) {
                $creation = ['possible' => false, 'reason' => "the entity's \"{$name}\" property is required but not administered; declare a field for it or override create()"];

                break;
            }
        }

        $ownedRelations = array_values(array_map(
            static fn (array $inverse): string => $inverse['name'],
            array_filter($entity['inverses'], static fn (array $inverse): bool => $inverse['owned']),
        ));

        // Soft deletion is a resource convention: the named nullable
        // timestamp property is set instead of the row being removed.
        $softDelete = null;
        $softProperty = $instance->softDeletes();

        if ($softProperty !== null) {
            $mapped = $properties[$softProperty] ?? throw ResourceCompileException::resource($class, "softDeletes() names \"{$softProperty}\", which is not a mapped property of {$entity['class']}");

            if ($mapped['type'] !== 'timestamp' || !$mapped['nullable']) {
                throw ResourceCompileException::resource($class, "softDeletes() names \"{$softProperty}\", which is not a nullable DateTimeImmutable property");
            }

            $softDelete = ['field' => $softProperty, 'column' => $mapped['column']];
        }

        $searchColumns = [];

        foreach ($compiledFields as $name => $field) {
            if ($field['searchable'] === true && isset($field['column'])) {
                // a rich-text field is searched through its plain-text column
                $searchColumns[$name] = $field['searchColumn'] ?? $field['column'];
            }
        }

        $groupable = array_keys(array_filter(
            $compiledFields,
            static fn (array $f): bool => $f['sortable'] === true && isset($f['column']) && in_array($f['type'], self::GROUPABLE, true),
        ));

        $summaries = [];

        foreach ($compiledFields as $name => $field) {
            if ($field['summary'] !== null) {
                $summaries[$name] = ['function' => $field['summary'], 'column' => $field['column']];
            }
        }

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

            $actionFields = [];

            foreach ($action->fields() as $field) {
                if (isset($actionFields[$field->name()])) {
                    throw ResourceCompileException::field($actionClass, $field->name(), 'is declared twice');
                }

                $actionFields[$field->name()] = self::compileLoose($field, $slugByClass, $actionClass);
            }

            self::checkConditions($actionFields, $actionClass);

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
                'extra' => $dependencies($indexFields),
                'defaultSort' => $defaultSort,
                'perPage' => $instance->perPage(),
                'perPageOptions' => $instance->perPageOptions(),
                'maxPerPage' => $instance->maxPerPage(),
                'search' => array_keys($searchColumns),
                'groupable' => $groupable,
                'summaries' => array_keys($summaries),
                'exportLimit' => $instance->exportLimit(),
                'exportQueueLimit' => $instance->exportQueueLimit(),
                'bulkLimit' => $instance->bulkLimit(),
            ],
            'show_view' => ['fields' => $showFields, 'extra' => $dependencies($showFields)],
            'policy' => $instance->policy(),
            'entityProperties' => $entityProperties,
            'timestamps' => $timestamps,
            'creation' => $creation,
            'ownedRelations' => $ownedRelations,
            'softDelete' => $softDelete,
            'searchColumns' => $searchColumns,
            'summaryPlan' => $summaries,
            'replicable' => $instance->replicable(),
            'globallySearchable' => $instance->globallySearchable() && $searchColumns !== [],
            'searchResult' => self::searchResult($instance, $compiledFields, $class),
            'badge' => self::badge($instance, $compiledFields, $class),
            'actions' => array_values($actions),
            'widgets' => WidgetCompiler::widgets($instance->widgets(), $class),
        ];
    }

    /** How a computed value is typed on the wire, by what it is shown as. */
    private const array COMPUTED_VALUE_TYPES = ['text' => 'string', 'number' => 'float', 'money' => 'int', 'boolean' => 'bool', 'date' => 'string', 'datetime' => 'string', 'enum' => 'string'];

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

        if (!method_exists($class, $method) || !(new \ReflectionMethod($class, $method))->isPublic() || (new \ReflectionMethod($class, $method))->isStatic()) {
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

        if ($array['computed']['as'] === 'enum' && ($array['options'] ?? []) === []) {
            throw ResourceCompileException::field($class, $name, 'is computed as an enum but lists no options; call options()');
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

                if ($type === null || in_array($type, ['computed', 'hasOne', 'hasMany', 'manyToMany'], true)) {
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
            throw new ResourceCompileException("{$class}::badge() has no colour.");
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
                foreach ($conditions as $child) {
                    self::checkBadgeFilter(is_array($child) ? $child : [], $fields, $class);
                }

                continue;
            }

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
    }

    /** The types a search subtitle shows as they are stored. */
    private const array SUBTITLE_TYPES = ['string', 'email', 'url', 'slug', 'text', 'number', 'enum'];

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

        if ($subtitle !== null && !in_array($fields[$subtitle]['type'] ?? null, self::SUBTITLE_TYPES, true)) {
            throw new ResourceCompileException("{$class}::searchSubtitle() names \"{$subtitle}\", which is not a text, number or enum field of the resource.");
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

                if (($array['repeater'] ?? null) !== null && !$inverse['owned']) {
                    throw ResourceCompileException::field($class, $name, 'is a repeater, which needs an owned #[HasMany]: the parent writes and deletes its rows');
                }
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
            $name = $property['name'];

            if ($name === $entity['version']) {
                continue;
            }

            if ($name === $entity['id']) {
                $fields[] = Id::make($name);

                continue;
            }

            if ($property['target'] !== null) {
                $slug = $this->slugByEntity[$property['target']] ?? null;

                if ($slug !== null) {
                    $fields[] = BelongsTo::to($name, $this->classOfSlug($slug))->sortable();
                }

                continue;
            }

            $field = match (true) {
                $property['enum'] !== null => Enum::make($name)->of($property['enum'])->filterable(),
                $property['type'] === 'string' => Text::make($name)->searchable(),
                $property['type'] === 'int' => Number::make($name),
                $property['type'] === 'float' => Number::make($name)->decimals(2),
                $property['type'] === 'bool' => Boolean::make($name)->filterable(),
                $property['type'] === 'date' => Date::make($name)->filterable(),
                default => DateTime::make($name)->filterable(),
            };

            $field->sortable();

            if (in_array($property['column'], ['created_at', 'updated_at', 'deleted_at'], true)) {
                $field->readonly()->hideFromForms();
            }

            $fields[] = $field;
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
            if (($field['valueType'] ?? null) === 'string' && ($field['enum'] ?? null) === null && !in_array($field['type'], ['password', 'json', 'keyValue', 'file', 'image', 'color', 'richtext', 'markdown', 'text'], true)) {
                return $name;
            }
        }

        return null;
    }
}
