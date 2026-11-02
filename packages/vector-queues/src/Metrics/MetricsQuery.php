<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues\Metrics;

use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Queues\FailedJobFilter;
use Kinetis\Vector\Queues\QueueNames;

/**
 * One validated `GET /vector/queues/metrics` query: `queue` (a watched
 * queue), `class` (a fully-qualified class name), and the window `from`
 * and `to` — unix seconds, each taken to the start of its minute, both
 * minutes included. `to` defaults to now and `from` to DEFAULT_MINUTES
 * minutes before `to`, the minute `to` falls in counted; the window spans
 * at most MAX_MINUTES minutes (7 days). Any other parameter, and any bad
 * value, is a 422 naming it, as {@see FailedJobFilter} refuses them.
 */
final readonly class MetricsQuery
{
    public const int DEFAULT_MINUTES = 60;

    public const int MAX_MINUTES = 7 * 24 * 60;

    private const array PARAMETERS = ['queue', 'class', 'from', 'to'];

    /** Unix seconds: a whole number, at most twelve digits. */
    private const string SECONDS = '/^\d{1,12}$/D';

    private function __construct(
        public ?string $queue,
        public ?string $class,
        /** The first minute's bucket. */
        public int $from,
        /** The last minute's bucket. */
        public int $to,
    ) {}

    /** @param array<array-key, mixed> $params */
    public static function fromQueryParams(array $params, QueueNames $queues, int $now): self
    {
        $violations = [];

        foreach (array_keys($params) as $key) {
            if (!in_array($key, self::PARAMETERS, true)) {
                $violations[] = new Violation([(string) $key], 'unknown_parameter', 'is not a metrics parameter; the parameters are ' . implode(', ', self::PARAMETERS) . '.');
            }
        }

        $string = static function (string $key) use ($params, &$violations): ?string {
            $value = $params[$key] ?? null;

            if ($value === null || $value === '') {
                return null;
            }

            if (!is_string($value)) {
                $violations[] = new Violation([$key], 'type_mismatch', 'must be a single value.');

                return null;
            }

            return $value;
        };

        $queue = $string('queue');

        if ($queue !== null && !$queues->has($queue)) {
            $violations[] = new Violation(['queue'], 'invalid_choice', 'is not a queue this dashboard watches.', ['choices' => $queues->names]);
            $queue = null;
        }

        $class = $string('class');

        if ($class !== null) {
            $class = ltrim($class, '\\');

            if (strlen($class) > 255 || preg_match(FailedJobFilter::CLASS_NAME, $class) !== 1) {
                $violations[] = new Violation(['class'], 'invalid_format', 'must be a fully-qualified class name.');
                $class = null;
            }
        }

        $from = self::seconds('from', $string('from'), $violations);
        $to = self::seconds('to', $string('to'), $violations);

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        $last = JobMetricsStore::bucket($to ?? $now);
        $first = $from === null ? $last - (self::DEFAULT_MINUTES - 1) * JobMetricsStore::BUCKET_SECONDS : JobMetricsStore::bucket($from);

        if ($first > $last) {
            throw ValidationException::fromViolations([new Violation(['to'], 'out_of_range', 'must not be before from.')]);
        }

        if (($last - $first) / JobMetricsStore::BUCKET_SECONDS + 1 > self::MAX_MINUTES) {
            throw ValidationException::fromViolations([new Violation(['from'], 'out_of_range', 'the window from from to to spans at most ' . self::MAX_MINUTES . ' minutes (7 days).')]);
        }

        return new self($queue, $class, $first, $last);
    }

    /** @param list<Violation> $violations */
    private static function seconds(string $key, ?string $value, array &$violations): ?int
    {
        if ($value === null) {
            return null;
        }

        if (preg_match(self::SECONDS, $value) !== 1) {
            $violations[] = new Violation([$key], 'invalid_format', 'must be a unix time in whole seconds, such as 1800000000.');

            return null;
        }

        return (int) $value;
    }
}
