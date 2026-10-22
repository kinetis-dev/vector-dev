<?php

declare(strict_types=1);

namespace Kinetis\Vector\Widgets;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Http\CurrentUserInterface;

/**
 * What a widget computes for: the selected range as two instants (and
 * the range before it, for a comparison), and the user asking.
 */
final readonly class WidgetRequest
{
    public function __construct(
        public string $range,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public DateTimeImmutable $previousFrom,
        public DateTimeImmutable $previousTo,
        public CurrentUserInterface $user,
    ) {}

    /**
     * A range key such as `7d`, `30d`, `90d`, `1y`, `24h` or `all`,
     * ending now; the previous range is the same length before it.
     */
    public static function for(string $range, CurrentUserInterface $user, ?DateTimeImmutable $now = null): self
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $interval = self::interval($range) ?? throw new \InvalidArgumentException("\"{$range}\" is not a range.");

        if ($interval === false) {
            $from = new DateTimeImmutable('1970-01-01', new DateTimeZone('UTC'));

            return new self($range, $from, $now, $from, $from, $user);
        }

        $from = $now->sub($interval);

        return new self($range, $from, $now, $from->sub($interval), $from, $user);
    }

    /** Whether $range is a key this grammar reads: what a widget's ranges() may declare. */
    public static function isRange(string $range): bool
    {
        return self::interval($range) !== null;
    }

    /** The range's length; false for `all`, null for a key outside the grammar. */
    private static function interval(string $range): \DateInterval|false|null
    {
        return match (true) {
            $range === 'all' => false,
            preg_match('/^(\d{1,3})d$/D', $range, $m) === 1 => new \DateInterval("P{$m[1]}D"),
            preg_match('/^(\d{1,3})h$/D', $range, $m) === 1 => new \DateInterval("PT{$m[1]}H"),
            preg_match('/^(\d{1,2})y$/D', $range, $m) === 1 => new \DateInterval("P{$m[1]}Y"),
            preg_match('/^(\d{1,3})m$/D', $range, $m) === 1 => new \DateInterval("P{$m[1]}M"),
            default => null,
        };
    }

    /** The number of days the range spans, at least 1. */
    public function days(): int
    {
        return max(1, (int) ceil(($this->to->getTimestamp() - $this->from->getTimestamp()) / 86400));
    }

    /** `Y-m-d H:i:s` in UTC, as a DATETIME column compares it. */
    public function sql(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
