<?php

declare(strict_types=1);

namespace Kinetis\Vector\Validation;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Kinetis\Orm\Date;
use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;

/**
 * The earliest and latest value a date or date-time field takes, what
 * minDate() and maxDate() declare: a day (`YYYY-MM-DD`) for a date, an
 * instant (ISO 8601 in UTC) for a date-time, or the relative `today` and
 * `now`. A relative bound is resolved each time a value is checked,
 * never when the resource compiles, so a long-running worker never holds
 * a stale one.
 *
 * `today` is the current day somewhere on Earth: a minimum of `today`
 * admits the earliest current day (UTC-12), a maximum the latest
 * (UTC+14), so the day a form offers in its user's own time zone is
 * always admitted. `now` is the current instant, which has no zone.
 *
 * A date range's `{from, to}` is checked bound by bound, each at its own
 * path. A value that is not the field's own text (a type mismatch the
 * converter reports) is left alone.
 */
final readonly class DateBounds implements Constraint
{
    public const string DATE = 'date';
    public const string DATETIME = 'datetime';
    public const string TODAY = 'today';
    public const string NOW = 'now';

    /** The time zones furthest behind and ahead of UTC: where the current day starts last and first. */
    private const string EARLIEST_ZONE = '-12:00';
    private const string LATEST_ZONE = '+14:00';

    public function __construct(
        private string $kind,
        private ?string $min,
        private ?string $max,
    ) {}

    /**
     * A bound as the constraint holds it, in the field's own format: a
     * day or `today` for a date, a UTC instant or `now` for a date-time.
     *
     * @throws InvalidArgumentException a bound the kind does not take
     */
    public static function normalize(string $kind, Date|DateTimeInterface|string $bound): string
    {
        if ($kind === self::DATE) {
            return match (true) {
                $bound instanceof Date => (string) $bound,
                $bound instanceof DateTimeInterface => $bound->format('Y-m-d'),
                $bound === self::TODAY => $bound,
                self::isDay($bound) => $bound,
                default => throw new InvalidArgumentException("a date is bounded by a day as YYYY-MM-DD, a Kinetis\\Orm\\Date, a DateTimeInterface or 'today', not \"{$bound}\""),
            };
        }

        if ($bound instanceof Date) {
            throw new InvalidArgumentException('a date-time is bounded by an instant: a DateTimeInterface, an ISO 8601 date-time or \'now\', not a Kinetis\\Orm\\Date');
        }

        if ($bound === self::NOW) {
            return $bound;
        }

        $instant = $bound instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($bound) : self::instant($bound);

        return $instant?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z')
            ?? throw new InvalidArgumentException('a date-time is bounded by an ISO 8601 date-time with its zone, a DateTimeInterface or \'now\', not "' . (is_string($bound) ? $bound : get_debug_type($bound)) . '"');
    }

    /**
     * Whether an absolute minimum falls after an absolute maximum, so no
     * value could satisfy both; relative bounds are never compared.
     */
    public static function crossed(string $kind, ?string $min, ?string $max): bool
    {
        if ($min === null || $max === null || in_array(self::TODAY, [$min, $max], true) || in_array(self::NOW, [$min, $max], true)) {
            return false;
        }

        return $kind === self::DATE ? $min > $max : self::instant($min) > self::instant($max);
    }

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        return $this->check($value, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /** The check validate() runs, at the instant `$now`: what `today` and `now` resolve against. */
    public function check(mixed $value, DateTimeImmutable $now): ?Violation
    {
        // a date range: each bound on its own
        if (is_array($value)) {
            foreach (['from', 'to'] as $end) {
                $violation = isset($value[$end]) ? $this->check($value[$end], $now) : null;

                if ($violation !== null) {
                    return $violation->under($end);
                }
            }

            return null;
        }

        if (!is_string($value)) {
            return null;
        }

        return $this->kind === self::DATE ? $this->checkDay($value, $now) : $this->checkInstant($value, $now);
    }

    private function checkDay(string $value, DateTimeImmutable $now): ?Violation
    {
        // a date field on a date-time property may be sent an instant: its day in UTC is the one stored
        if (!self::isDay($value)) {
            $value = self::instant($value)?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');

            if ($value === null) {
                return null;
            }
        }

        $min = $this->min === self::TODAY ? $now->setTimezone(new DateTimeZone(self::EARLIEST_ZONE))->format('Y-m-d') : $this->min;
        $max = $this->max === self::TODAY ? $now->setTimezone(new DateTimeZone(self::LATEST_ZONE))->format('Y-m-d') : $this->max;

        if ($min !== null && $value < $min) {
            return new Violation([], 'date_too_early', "must be on or after {$this->min}.", ['min' => $this->min]);
        }

        if ($max !== null && $value > $max) {
            return new Violation([], 'date_too_late', "must be on or before {$this->max}.", ['max' => $this->max]);
        }

        return null;
    }

    private function checkInstant(string $value, DateTimeImmutable $now): ?Violation
    {
        $instant = self::instant($value);

        if ($instant === null) {
            return null;
        }

        $min = self::instantBound($this->min, $now);
        $max = self::instantBound($this->max, $now);

        if ($min !== null && $instant < $min) {
            return new Violation([], 'date_too_early', "must be at or after {$this->min}.", ['min' => $this->min]);
        }

        if ($max !== null && $instant > $max) {
            return new Violation([], 'date_too_late', "must be at or before {$this->max}.", ['max' => $this->max]);
        }

        return null;
    }

    /** An instant bound as of `$now`: `now` is that instant, anything else the instant it names. */
    private static function instantBound(?string $bound, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($bound === null) {
            return null;
        }

        return $bound === self::NOW ? $now : self::instant($bound);
    }

    private static function isDay(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** An ISO 8601 date-time (a date alone is midnight UTC), or null for anything else. */
    private static function instant(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?)?(?:Z|[+-]\d{2}:?\d{2})?$/D', $value) !== 1) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /** JSON Schema has no keyword for a date's range, and a relative bound none at all: the rule is the server's own. */
    #[\Override]
    public function schema(): array
    {
        return [];
    }
}
