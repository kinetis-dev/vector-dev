<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use Kinetis\Validation\Constraints\MaxLength;
use Kinetis\Validation\Constraints\MinLength;
use Kinetis\Validation\Constraints\NotBlank;

/**
 * One administered attribute of a resource, as compile-time data. A
 * field never sees a value: the request-time serializer works from the
 * array toArray() produces, keyed by `type`, so the compile boundary
 * of §4.3 holds — nothing here survives into a worker but plain data.
 */
abstract class Field
{
    public const string VIEW_INDEX = 'index';
    public const string VIEW_SHOW = 'show';
    public const string VIEW_CREATE = 'create';
    public const string VIEW_UPDATE = 'update';

    protected string $label;

    protected bool $sortable = false;

    protected bool $searchable = false;

    protected bool $filterable = false;

    protected bool $readonly = false;
    protected bool $inline = false;

    /** The column holds a unique value: a copy of a record starts without it, and an import may match rows by it. */
    protected bool $unique = false;

    protected ?string $help = null;

    protected ?string $component = null;

    /** @var list<string> */
    protected array $views = [self::VIEW_INDEX, self::VIEW_SHOW, self::VIEW_CREATE, self::VIEW_UPDATE];

    /** @var list<array{0: class-string, 1: list<mixed>}> */
    protected array $rules = [];

    /** @var array<string, mixed> */
    protected array $props = [];

    /** The aggregate a list may show under this column: sum, avg, min, max or count. */
    protected ?string $summary = null;

    protected mixed $default = null;

    /** How many columns of its section's grid the field takes in a layout: 1, 2 or 'full'. */
    protected int|string|null $columnSpan = null;

    protected bool $hasDefault = false;

    /** The operators a condition may use. */
    public const array CONDITION_OPERATORS = ['eq', 'ne', 'in', 'nin', 'null', 'nnull'];

    /**
     * Form behaviour that depends on other fields of the same form, each
     * kind a list of conditions that must all hold.
     *
     * @var array{visible: list<array{field: string, op: string, value: mixed}>, required: list<array{field: string, op: string, value: mixed}>, disabled: list<array{field: string, op: string, value: mixed}>}
     */
    protected array $conditions = ['visible' => [], 'required' => [], 'disabled' => []];

    final protected function __construct(protected readonly string $name, ?string $label)
    {
        $this->label = $label ?? self::labelFrom($name);
    }

    public static function make(string $name, ?string $label = null): static
    {
        return new static($name, $label);
    }

    /** The descriptor's `type`, fixed per class. */
    abstract public function type(): string;

    public function name(): string
    {
        return $this->name;
    }

    /** Whether the field maps one column of the entity (false for inverse relations). */
    public function mapsColumn(): bool
    {
        return true;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function sortable(bool $sortable = true): static
    {
        $this->sortable = $sortable;

        return $this;
    }

    public function searchable(bool $searchable = true): static
    {
        $this->searchable = $searchable;

        return $this;
    }

    public function filterable(bool $filterable = true): static
    {
        $this->filterable = $filterable;

        return $this;
    }

    public function readonly(bool $readonly = true): static
    {
        $this->readonly = $readonly;

        return $this;
    }

    /**
     * Editable in place in the list: a click on the cell edits it, and
     * the list saves every changed cell together (POST …/changes). For
     * simple values only — text-like, number, money, boolean, enum, date,
     * date-time and belongsTo fields — and never a read-only one.
     */
    public function inlineEditable(bool $inline = true): static
    {
        $this->inline = $inline;

        return $this;
    }

    /**
     * Marks the value as unique among the resource's records — what the
     * column's unique index already enforces. A duplicated record's form
     * starts without it, and a CSV import may use it as the key that
     * matches rows to records. The ORM's metadata does not carry indexes,
     * so the field says it.
     */
    public function unique(bool $unique = true): static
    {
        $this->unique = $unique;

        return $this;
    }

    public function help(string $help): static
    {
        $this->help = $help;

        return $this;
    }

    /**
     * Shown in the form only while $field compares to $value by $op (eq,
     * ne, in, nin, null, nnull). Repeated calls must all hold. While
     * hidden, a submitted value is ignored and the field is not required.
     */
    public function visibleWhen(string $field, string $op, mixed $value = null): static
    {
        return $this->when('visible', $field, $op, $value);
    }

    /** Required while $field compares to $value by $op; repeated calls must all hold. */
    public function requiredWhen(string $field, string $op, mixed $value = null): static
    {
        return $this->when('required', $field, $op, $value);
    }

    /** Not editable while $field compares to $value by $op; a submitted value is then ignored. */
    public function disabledWhen(string $field, string $op, mixed $value = null): static
    {
        return $this->when('disabled', $field, $op, $value);
    }

    /** The UI component name resolving this field, overriding the type's default. */
    public function component(string $component): static
    {
        $this->component = $component;

        return $this;
    }

    public function hideFromIndex(): static
    {
        return $this->without(self::VIEW_INDEX);
    }

    public function hideFromShow(): static
    {
        return $this->without(self::VIEW_SHOW);
    }

    public function hideFromForms(): static
    {
        return $this->without(self::VIEW_CREATE)->without(self::VIEW_UPDATE);
    }

    public function onlyOnIndex(): static
    {
        $this->views = [self::VIEW_INDEX];

        return $this;
    }

    public function onlyOnShow(): static
    {
        $this->views = [self::VIEW_SHOW];

        return $this;
    }

    public function onlyOnForms(): static
    {
        $this->views = [self::VIEW_CREATE, self::VIEW_UPDATE];

        return $this;
    }

    /**
     * A framework constraint the value must satisfy, stored as the
     * `{class, args}` pair the framework itself builds a rule from.
     *
     * @param class-string $constraint
     */
    public function rule(string $constraint, mixed ...$args): static
    {
        $this->rules[] = [$constraint, array_values($args)];

        return $this;
    }

    public function required(): static
    {
        return $this->rule(NotBlank::class);
    }

    public function max(int $length): static
    {
        return $this->rule(MaxLength::class, $length);
    }

    public function min(int $length): static
    {
        return $this->rule(MinLength::class, $length);
    }

    /** @param array<string, mixed> $props */
    public function props(array $props): static
    {
        $this->props = [...$this->props, ...$props];

        return $this;
    }

    /**
     * Publishes an aggregate of this column over every row a list
     * matched, computed in one statement when a client asks for
     * summaries. Only a column-mapped numeric field may carry one; the
     * compiler refuses anything else.
     */
    public function summarize(string $function): static
    {
        $function = strtolower($function);

        if (!in_array($function, ['sum', 'avg', 'min', 'max', 'count'], true)) {
            throw new \InvalidArgumentException("\"{$function}\" is not a summary; use sum, avg, min, max or count.");
        }

        $this->summary = $function;

        return $this;
    }

    /**
     * The width the field takes in a layout's grid: one column, two, or
     * the whole row ('full'). Declaring it gives the resource a layout
     * even without sections or tabs.
     */
    public function columnSpan(int|string $span): static
    {
        if (!in_array($span, [1, 2, 'full'], true)) {
            throw new \InvalidArgumentException("\"{$this->name}\": columnSpan is 1, 2 or 'full'.");
        }

        $this->columnSpan = $span;

        return $this;
    }

    /** The span columnSpan() declared, or null to let the UI size the field by its type. */
    public function layoutSpan(): int|string|null
    {
        return $this->columnSpan;
    }

    /** The value a form, an action dialog or a settings page starts from. */
    public function default(mixed $value): static
    {
        $this->default = $value;
        $this->hasDefault = true;

        return $this;
    }

    /**
     * The compiled form. `nullable` and `column` are filled in by the
     * compiler from the entity's metadata.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type(),
            'label' => $this->label,
            'sortable' => $this->sortable,
            'searchable' => $this->searchable,
            'filterable' => $this->filterable,
            'readonly' => $this->readonly,
            'inline' => $this->inline,
            'unique' => $this->unique,
            'help' => $this->help,
            'component' => $this->component,
            'views' => $this->views,
            'rules' => array_map(
                static fn (array $rule): array => ['constraint' => $rule[0], 'args' => $rule[1]],
                $this->rules,
            ),
            'props' => $this->props,
            'summary' => $this->summary,
            'default' => $this->default,
            'hasDefault' => $this->hasDefault,
            'conditions' => $this->conditions,
        ];
    }

    public static function labelFrom(string $name): string
    {
        $words = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $name) ?? $name;

        return ucfirst(strtolower(str_replace('_', ' ', $words)));
    }

    /** @param 'visible'|'required'|'disabled' $kind */
    private function when(string $kind, string $field, string $op, mixed $value): static
    {
        if (!in_array($op, self::CONDITION_OPERATORS, true)) {
            throw new \InvalidArgumentException("\"{$this->name}\": a condition's operator is one of " . implode(', ', self::CONDITION_OPERATORS) . ", not \"{$op}\".");
        }

        $scalar = static fn (mixed $v): bool => $v === null || is_scalar($v);
        $valid = match ($op) {
            'null', 'nnull' => $value === null,
            'in', 'nin' => is_array($value) && array_is_list($value) && $value !== [] && array_all($value, $scalar),
            default => $scalar($value),
        };

        if (!$valid) {
            throw new \InvalidArgumentException("\"{$this->name}\": a \"{$op}\" condition takes " . match ($op) {
                'null', 'nnull' => 'no value',
                'in', 'nin' => 'a non-empty list of scalars',
                default => 'one scalar',
            } . '.');
        }

        $this->conditions[$kind][] = ['field' => $field, 'op' => $op, 'value' => $value];

        return $this;
    }

    private function without(string $view): static
    {
        $this->views = array_values(array_filter($this->views, static fn (string $v): bool => $v !== $view));

        return $this;
    }
}
