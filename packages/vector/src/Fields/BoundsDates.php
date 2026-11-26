<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use DateTimeInterface;
use InvalidArgumentException;
use Kinetis\Orm\Date as Day;
use Kinetis\Vector\Validation\DateBounds;
use LogicException;

/**
 * minDate() and maxDate(): the earliest and latest value of a date, a
 * date-time or both ends of a date range, checked by the DateBounds rule
 * on every write and mirrored by the form. A bound is a day or an
 * instant in the field's own format, or `today` (a date) and `now` (a
 * date-time), resolved each time a value is checked. min() and max()
 * bound a text's length, which a date has none of, so they are refused.
 *
 * @phpstan-require-extends Field
 */
trait BoundsDates
{
    private Day|DateTimeInterface|string|null $minDate = null;

    private Day|DateTimeInterface|string|null $maxDate = null;

    /**
     * The earliest value: a `Kinetis\Orm\Date` or `DateTimeInterface`, a
     * day (`2026-01-01`) or ISO 8601 date-time in the field's own format,
     * or `today` for a date and `now` for a date-time.
     */
    public function minDate(Day|DateTimeInterface|string $bound): static
    {
        $this->minDate = $bound;
        $this->checkBounds();

        return $this;
    }

    /** The latest value, as minDate() takes it. */
    public function maxDate(Day|DateTimeInterface|string $bound): static
    {
        $this->maxDate = $bound;
        $this->checkBounds();

        return $this;
    }

    /** @throws LogicException a date is bounded by minDate(), not by a length */
    #[\Override]
    public function min(int $length): static
    {
        throw new LogicException("\"{$this->name}\": min() bounds a text's length; bound a {$this->type()} field with minDate().");
    }

    /** @throws LogicException a date is bounded by maxDate(), not by a length */
    #[\Override]
    public function max(int $length): static
    {
        throw new LogicException("\"{$this->name}\": max() bounds a text's length; bound a {$this->type()} field with maxDate().");
    }

    /**
     * The DateBounds rule for values of $kind (`date` or `datetime`), as
     * a compiled `{constraint, args}` pair, or null when the field has no
     * bound.
     *
     * @return array{constraint: class-string<DateBounds>, args: list<string|null>}|null
     * @throws InvalidArgumentException a bound $kind does not take, or a minimum after the maximum
     */
    public function boundsRule(string $kind): ?array
    {
        if ($this->minDate === null && $this->maxDate === null) {
            return null;
        }

        $min = $this->minDate === null ? null : DateBounds::normalize($kind, $this->minDate);
        $max = $this->maxDate === null ? null : DateBounds::normalize($kind, $this->maxDate);

        if (DateBounds::crossed($kind, $min, $max)) {
            throw new InvalidArgumentException("\"{$this->name}\": minDate() {$min} is after maxDate() {$max}.");
        }

        return ['constraint' => DateBounds::class, 'args' => [$kind, $min, $max]];
    }

    /** Refuses a bad bound where it is declared, for a field that knows its kind (checkedBounds()). */
    abstract protected function checkBounds(): void;

    /**
     * The bounds checked for values of $kind, the message naming the field.
     *
     * @throws InvalidArgumentException
     */
    private function checkedBounds(string $kind): void
    {
        try {
            $this->boundsRule($kind);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(str_starts_with($e->getMessage(), '"') ? $e->getMessage() : "\"{$this->name}\": {$e->getMessage()}.", 0, $e);
        }
    }

    /**
     * The compiled field with its bounds rule for values of $kind.
     *
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    private function withBounds(array $array, string $kind): array
    {
        $rule = $this->boundsRule($kind);

        if ($rule !== null) {
            $array['rules'][] = $rule;
        }

        return $array;
    }
}
