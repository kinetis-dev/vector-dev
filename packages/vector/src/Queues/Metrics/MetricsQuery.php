<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues\Metrics;

use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Queues\FailedJobFilter;
use Kinetis\Vector\Queues\QueryParameters;
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
        QueryParameters::rejectUnknown($params, self::PARAMETERS, 'is not a metrics parameter; the parameters are ', $violations);
        $queue = QueryParameters::queue($params, $queues, $violations);
        $class = QueryParameters::className($params, $violations);
        $from = self::seconds('from', QueryParameters::string($params, 'from', $violations), $violations);
        $to = self::seconds('to', QueryParameters::string($params, 'to', $violations), $violations);

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
