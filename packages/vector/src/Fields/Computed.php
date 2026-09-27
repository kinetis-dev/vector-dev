<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

/**
 * A read-only value a method of the resource produces from the record:
 * `Computed::make('lineTotal', 'Total')->as('money')->currency('EUR')->from(['quantity', 'unitPriceCents'])->method('lineTotal')`.
 *
 * `method()` names a public method of the resource class taking the
 * serialized record (`array<string, mixed>`) and returning the value;
 * `from()` names the fields it reads, which every list and record then
 * carries even when they are not displayed. `as()` names how the UI
 * shows the value. Computed per record in PHP, after the query: no
 * statement of its own. Never sortable, filterable, searchable,
 * summarized, written or imported; shown on the list and the record
 * page only.
 */
final class Computed extends Field
{
    /** The kinds a computed value is shown as. */
    public const array AS = ['text', 'number', 'money', 'boolean', 'date', 'datetime', 'enum'];

    protected array $views = [self::VIEW_INDEX, self::VIEW_SHOW];

    protected bool $readonly = true;

    private string $as = 'text';

    /** @var list<string> */
    private array $from = [];

    private ?string $method = null;

    /** @var list<array{value: string|int, label: string, color: string|null}> */
    private array $options = [];

    public function type(): string
    {
        return 'computed';
    }

    public function mapsColumn(): bool
    {
        return false;
    }

    /** How the value is shown: text, number, money, boolean, date, datetime or enum. */
    public function as(string $as): self
    {
        if (!in_array($as, self::AS, true)) {
            throw new \InvalidArgumentException("\"{$this->name}\": as() is one of " . implode(', ', self::AS) . ", not \"{$as}\".");
        }

        $this->as = $as;

        return $this;
    }

    /** @param list<string> $fields the fields the method reads */
    public function from(array $fields): self
    {
        $this->from = $fields;

        return $this;
    }

    /** The public method of the resource class that computes the value from the record. */
    public function method(string $method): self
    {
        $this->method = $method;

        return $this;
    }

    /** A money value's fixed currency. */
    public function currency(string $code): self
    {
        return $this->props(['currency' => strtoupper($code)]);
    }

    /** A money value's currency, read from a field of the record, or of a related one (`order.currency`). */
    public function currencyField(string $field): self
    {
        return $this->props(['currencyField' => $field]);
    }

    /** A number's decimals. */
    public function decimals(int $decimals): self
    {
        return $this->props(['decimals' => $decimals]);
    }

    /**
     * An enum value's choices: value => label, with an optional colour each.
     *
     * @param array<string|int, string> $labels
     * @param array<string|int, string> $colors
     */
    public function options(array $labels, array $colors = []): self
    {
        $this->options = [];

        foreach ($labels as $value => $label) {
            $this->options[] = ['value' => $value, 'label' => $label, 'color' => $colors[$value] ?? null];
        }

        return $this;
    }

    public function toArray(): array
    {
        $array = [...parent::toArray(), 'computed' => ['as' => $this->as, 'from' => $this->from, 'method' => $this->method]];

        if ($this->as === 'enum') {
            $array['options'] = $this->options;
        }

        return $array;
    }
}
