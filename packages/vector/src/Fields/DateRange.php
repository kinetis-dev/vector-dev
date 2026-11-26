<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use LogicException;

/**
 * A period between two days or two instants, kept in two properties of
 * the entity: `DateRange::between('promotion', 'promotionStartsOn',
 * 'promotionEndsOn')`. The field's own name is no property. Both
 * bounds are `Kinetis\Orm\Date` properties (a range of days) or both
 * `DateTimeImmutable` ones (a range of instants), which the compiler
 * reads from the entity; a range is nullable only when both are.
 *
 * On the wire its value is `{"from": …, "to": …}`, each in the format
 * of a Date or a DateTime field, or null. Both are set or neither is,
 * and `to` is never before `from`; required() asks for both.
 * minDate() and maxDate() bound both ends. A range is shown, edited,
 * imported and exported as one value (in a CSV cell, `from/to`), but is
 * never sorted, filtered or summarized as one: each of its properties
 * can be a field of a resource of its own, never of the same one.
 */
final class DateRange extends Field
{
    use BoundsDates;

    /** What separates the two ends in a CSV cell: an ISO 8601 interval's solidus. */
    public const string SEPARATOR = '/';

    private ?string $from = null;

    private ?string $to = null;

    /**
     * A range and the two properties it keeps.
     *
     * @param string $from the property holding the first day or instant
     * @param string $to the property holding the last
     */
    public static function between(string $name, string $from, string $to, ?string $label = null): static
    {
        $field = new static($name, $label);
        $field->from = $from;
        $field->to = $to;

        return $field;
    }

    /** @throws LogicException always: a range is declared with the two properties it keeps */
    #[\Override]
    public static function make(string $name, ?string $label = null): never
    {
        throw new LogicException("\"{$name}\": a date range maps two properties; declare it with them, as in DateRange::between('{$name}', 'startsOn', 'endsOn').");
    }

    #[\Override]
    public function type(): string
    {
        return 'dateRange';
    }

    #[\Override]
    public function mapsColumn(): bool
    {
        return false;
    }

    /** @throws LogicException a range is never sorted as one value */
    #[\Override]
    public function sortable(bool $sortable = true): static
    {
        return $sortable ? throw new LogicException("\"{$this->name}\": a date range is not sorted as one value; declare a Date or DateTime field for one of its properties on another resource to sort by it.") : $this;
    }

    /** @throws LogicException a range is never filtered as one value */
    #[\Override]
    public function filterable(bool $filterable = true): static
    {
        return $filterable ? throw new LogicException("\"{$this->name}\": a date range is not filtered as one value; declare a Date or DateTime field for one of its properties on another resource to filter by it.") : $this;
    }

    /** @throws LogicException a range has no column to be a unique key in */
    #[\Override]
    public function unique(bool $unique = true): static
    {
        return $unique ? throw new LogicException("\"{$this->name}\": a date range is not unique as one value; it has no column of its own to match records by.") : $this;
    }

    /** @throws LogicException a range is never summarized */
    #[\Override]
    public function summarize(string $function): static
    {
        throw new LogicException("\"{$this->name}\": a date range holds two dates, which a list cannot summarize.");
    }

    /** The bounds are checked once the compiler knows whether the range holds days or instants. */
    #[\Override]
    protected function checkBounds(): void {}

    /** @return array{from: string, to: string} the properties holding the two ends */
    public function properties(): array
    {
        return ['from' => (string) $this->from, 'to' => (string) $this->to];
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        return [...parent::toArray(), 'range' => ['kind' => null, ...$this->properties()]];
    }

    /**
     * A range as a CSV cell holds it: `from/to`, an ISO 8601 interval; an
     * empty cell for no range.
     *
     * @param array{from?: mixed, to?: mixed}|null $range
     */
    public static function cell(?array $range): string
    {
        if ($range === null) {
            return '';
        }

        return (is_string($range['from'] ?? null) ? $range['from'] : '') . self::SEPARATOR . (is_string($range['to'] ?? null) ? $range['to'] : '');
    }
}
