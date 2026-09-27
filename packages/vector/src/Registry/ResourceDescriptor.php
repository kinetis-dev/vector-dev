<?php

declare(strict_types=1);

namespace Kinetis\Vector\Registry;

use Kinetis\Vector\VectorResource;

/**
 * Typed, read-only access to one compiled resource. Holds the array the
 * compiler produced and nothing else.
 */
final readonly class ResourceDescriptor
{
    /** @var array<string, array<string, mixed>> field name => compiled field */
    private array $fields;

    /** @param array<string, mixed> $data */
    public function __construct(private array $data)
    {
        $fields = [];

        foreach ($data['fields'] as $field) {
            $fields[$field['name']] = $field;
        }

        $this->fields = $fields;
    }

    /**
     * The application's default currency (VECTOR_CURRENCY) given to each
     * currency field that has no default of its own and allows it.
     */
    public function withDefaultCurrency(?string $currency): self
    {
        if ($currency === null) {
            return $this;
        }

        $data = $this->data;
        $changed = false;

        foreach ($data['fields'] as $index => $field) {
            if ($field['type'] === 'currency' && $field['hasDefault'] !== true && in_array($currency, array_column($field['options'], 'value'), true)) {
                $data['fields'][$index]['default'] = $currency;
                $data['fields'][$index]['hasDefault'] = true;
                $changed = true;
            }
        }

        return $changed ? new self($data) : $this;
    }

    /**
     * This resource as a user who may not see $hidden sees it: those
     * fields leave the field list and every plan that names them (list
     * and show columns, sort, grouping, search, summaries), so nothing
     * downstream can read, filter or write them. The primary key cannot
     * be hidden. A hidden title falls back to the primary key, and a
     * hidden field a new entity requires makes creation impossible for
     * this user.
     *
     * @param list<string> $hidden
     */
    public function withoutFields(array $hidden): self
    {
        $hidden = array_values(array_diff(array_intersect($hidden, array_keys($this->fields)), [$this->primaryKeyField()]));

        if ($hidden === []) {
            return $this;
        }

        // a computed value computed from a hidden field would show it: it goes too
        foreach ($this->fields as $name => $field) {
            if (array_intersect($field['computed']['from'] ?? [], $hidden) !== [] && !in_array($name, $hidden, true)) {
                $hidden[] = $name;
            }
        }

        $keep = static fn (array $names): array => array_values(array_diff($names, $hidden));
        $data = $this->data;
        $data['fields'] = array_values(array_filter($data['fields'], static fn (array $field): bool => !in_array($field['name'], $hidden, true)));
        $data['columns'] = array_diff_key($data['columns'], array_flip($hidden));
        $data['searchColumns'] = array_diff_key($data['searchColumns'], array_flip($hidden));
        $data['summaryPlan'] = array_diff_key($data['summaryPlan'], array_flip($hidden));
        $data['table_view']['columns'] = $keep($data['table_view']['columns']);
        $data['table_view']['extra'] = $keep($data['table_view']['extra']);
        $data['table_view']['search'] = $keep($data['table_view']['search']);
        $data['table_view']['groupable'] = $keep($data['table_view']['groupable']);
        $data['table_view']['summaries'] = $keep($data['table_view']['summaries']);
        $data['table_view']['defaultSort'] = array_values(array_filter($data['table_view']['defaultSort'], static fn (array $sort): bool => !in_array($sort['field'], $hidden, true)));
        $data['show_view']['fields'] = $keep($data['show_view']['fields']);
        $data['show_view']['extra'] = $keep($data['show_view']['extra']);

        // the layout loses the hidden fields, and a section or tab they leave empty
        if (($data['layout'] ?? null) !== null) {
            $visible = static fn (string $name): bool => !in_array($name, $hidden, true);
            $data['layout'] = [
                'form' => LayoutCompiler::prune($data['layout']['form'], $visible),
                'show' => LayoutCompiler::prune($data['layout']['show'], $visible),
            ];
        }
        $data['globallySearchable'] = $data['globallySearchable'] && $data['searchColumns'] !== [];
        $data['searchResult'] = array_map(static fn (?string $field): ?string => in_array($field, $hidden, true) ? null : $field, $data['searchResult']);

        if (in_array($data['titleField'], $hidden, true)) {
            $data['titleField'] = $this->primaryKeyField();
        }

        if ($data['creation']['possible'] === true) {
            foreach ($hidden as $name) {
                $property = $data['entityProperties'][$name] ?? null;
                $required = $property !== null && in_array($property['kind'], ['column', 'belongsTo'], true) && $property['nullable'] !== true && $property['hasDefault'] !== true
                    && !in_array($name, $data['timestamps'], true);

                if ($required) {
                    $data['creation'] = ['possible' => false, 'reason' => "creating a record needs the \"{$name}\" field, which this account cannot see"];

                    break;
                }
            }
        }

        return new self($data);
    }

    public function slug(): string
    {
        return $this->data['slug'];
    }

    /** @return class-string */
    public function entityClass(): string
    {
        return $this->data['entity'];
    }

    /**
     * Every property of the entity, administered or not.
     *
     * @return array<string, array{kind: string, nullable: bool, hasDefault: bool, type: string|null, owned?: bool}>
     */
    public function entityProperties(): array
    {
        return $this->data['entityProperties'];
    }

    /** @return array{possible: bool, reason: string|null} */
    public function creation(): array
    {
        return $this->data['creation'];
    }

    /** @return array{created: string|null, updated: string|null} */
    public function timestamps(): array
    {
        return $this->data['timestamps'];
    }

    /** The optimistic-lock column, read with every record as `_version`. */
    public function versionColumn(): ?string
    {
        return $this->data['versionColumn'] ?? null;
    }

    public function versionField(): ?string
    {
        return $this->data['version'];
    }

    /**
     * The owned inverse relationships a delete must load first.
     *
     * @return list<string>
     */
    public function ownedRelations(): array
    {
        return $this->data['ownedRelations'];
    }

    /** @return array{field: string, column: string}|null */
    public function softDelete(): ?array
    {
        return $this->data['softDelete'];
    }

    /** @return array<string, string> field name => column, for every searchable field */
    public function searchColumns(): array
    {
        return $this->data['searchColumns'];
    }

    /** @return array<string, array{function: string, column: string}> */
    public function summaryPlan(): array
    {
        return $this->data['summaryPlan'];
    }

    /** @return list<string> */
    public function groupable(): array
    {
        return $this->data['table_view']['groupable'];
    }

    /** @return array{filter: array<string, mixed>, color: string, label: string|null}|null the menu badge */
    public function badge(): ?array
    {
        return $this->data['badge'];
    }

    /** @return array{subtitle: string|null, image: string|null} the fields a global search result shows */
    public function searchResult(): array
    {
        return $this->data['searchResult'];
    }

    public function globallySearchable(): bool
    {
        return $this->data['globallySearchable'];
    }

    public function exportLimit(): int
    {
        return $this->data['table_view']['exportLimit'];
    }

    public function exportQueueLimit(): int
    {
        return $this->data['table_view']['exportQueueLimit'];
    }

    public function bulkLimit(): int
    {
        return $this->data['table_view']['bulkLimit'];
    }

    /**
     * How the form and the show page group the fields, or null when the
     * resource declared no sections, tabs or spans.
     *
     * @return array{form: list<array<string, mixed>>, show: list<array<string, mixed>>}|null
     */
    public function layout(): ?array
    {
        return $this->data['layout'] ?? null;
    }

    public function replicable(): bool
    {
        return $this->data['replicable'] ?? true;
    }

    /** @return list<array<string, mixed>> */
    public function actions(): array
    {
        return $this->data['actions'];
    }

    /** @return array<string, mixed>|null */
    public function action(string $name): ?array
    {
        foreach ($this->data['actions'] as $action) {
            if ($action['name'] === $name) {
                return $action;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function widgets(): array
    {
        return $this->data['widgets'];
    }

    /** @return array<string, mixed>|null */
    public function widget(string $name): ?array
    {
        foreach ($this->data['widgets'] as $widget) {
            if ($widget['name'] === $name) {
                return $widget;
            }
        }

        return null;
    }

    public function labelSingular(): string
    {
        return $this->data['labelSingular'];
    }

    /** The connection the entity lives on, as its #[Entity] names it: `default` unless another. */
    public function connection(): string
    {
        return $this->data['connection'];
    }

    /** @return class-string<VectorResource> */
    public function resourceClass(): string
    {
        return $this->data['class'];
    }

    public function label(): string
    {
        return $this->data['label'];
    }

    public function table(): string
    {
        return $this->data['table'];
    }

    public function primaryKeyField(): string
    {
        return $this->data['primaryKey']['field'];
    }

    public function primaryKeyColumn(): string
    {
        return $this->data['primaryKey']['column'];
    }

    public function primaryKeyType(): string
    {
        return $this->data['primaryKey']['type'];
    }

    public function titleField(): string
    {
        return $this->data['titleField'];
    }

    public function titleColumn(): string
    {
        return $this->data['columns'][$this->data['titleField']];
    }

    /**
     * The computed fields among $names, with the resource method computing each.
     *
     * @param list<string> $names
     * @return array<string, string> field name => method
     */
    public function computedMethods(array $names): array
    {
        $methods = [];

        foreach ($names as $name) {
            $method = $this->fields[$name]['computed']['method'] ?? null;

            if (is_string($method)) {
                $methods[$name] = $method;
            }
        }

        return $methods;
    }

    /** @return array<string, array<string, mixed>> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return array<string, mixed>|null */
    public function field(string $name): ?array
    {
        return $this->fields[$name] ?? null;
    }

    /** @return array<string, string> field name => column, for every column-mapped field */
    public function columns(): array
    {
        return $this->data['columns'];
    }

    /**
     * The fields a list serializes: the displayed columns plus the ones
     * their display depends on.
     *
     * @return list<string>
     */
    public function indexFields(): array
    {
        /** @var list<string> */
        return [...$this->data['table_view']['columns'], ...$this->data['table_view']['extra']];
    }

    /** @return list<string> */
    public function showFields(): array
    {
        /** @var list<string> */
        return [...$this->data['show_view']['fields'], ...$this->data['show_view']['extra']];
    }

    /** @return list<array{field: string, order: 'asc'|'desc'}> */
    public function defaultSort(): array
    {
        return $this->data['table_view']['defaultSort'];
    }

    public function perPage(): int
    {
        return $this->data['table_view']['perPage'];
    }

    public function maxPerPage(): int
    {
        return $this->data['table_view']['maxPerPage'];
    }

    /** @return class-string<\Kinetis\Vector\Auth\ResourcePolicyInterface>|null */
    public function policy(): ?string
    {
        return $this->data['policy'];
    }

    /**
     * The descriptor as published to clients: the compiled array without the class names.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $public = $this->data;
        unset($public['class'], $public['entity'], $public['connection'], $public['columns'], $public['versionColumn'], $public['policy'], $public['entityProperties'], $public['timestamps'], $public['ownedRelations'], $public['searchColumns'], $public['summaryPlan']);
        $public['softDelete'] = $public['softDelete'] === null ? null : ['field' => $public['softDelete']['field']];
        $public['actions'] = array_map(static function (array $action): array {
            unset($action['class']);
            $action['fields'] = array_map(self::publicField(...), $action['fields']);

            return $action;
        }, $public['actions']);
        $public['widgets'] = array_map(static function (array $widget): array {
            unset($widget['class']);

            return $widget;
        }, $public['widgets']);
        $public['table'] = $public['table_view'];
        $public['show'] = $public['show_view'];
        unset($public['table_view'], $public['show_view']);
        $public['fields'] = array_map(self::publicField(...), $public['fields']);
        $public['layout'] ??= null;
        $public['replicable'] ??= true;

        return $public;
    }

    /**
     * A compiled field without its internals: no column, no enum class.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    public static function publicField(array $field): array
    {
        unset($field['column'], $field['enum']);

        if (isset($field['computed'])) {
            unset($field['computed']['method']);
        }

        if (isset($field['richtext'])) {
            unset($field['richtext']['textField'], $field['textColumn'], $field['searchColumn']);
        }

        if (isset($field['relation'])) {
            unset($field['relation']['targetTable'], $field['relation']['targetKey'], $field['relation']['foreignKey'], $field['relation']['joinTable'], $field['relation']['joinColumn'], $field['relation']['inverseJoinColumn']);

            unset($field['relation']['carry']);

            if (isset($field['relation']['dependsOn'])) {
                unset($field['relation']['dependsOn']['column']);
            }
        }

        return $field;
    }
}
