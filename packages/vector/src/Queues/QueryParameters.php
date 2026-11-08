<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues;

use Kinetis\Validation\Violation;

/**
 * The query parameters the queue dashboard's filters share — the known
 * names, the single-valued strings, `queue` and `class` — each read into
 * a value or a Violation appended to the caller's list, in the order the
 * caller reads them.
 *
 * @internal for FailedJobFilter and Metrics\MetricsQuery
 */
final readonly class QueryParameters
{
    private function __construct() {}

    /**
     * Appends an `unknown_parameter` violation for every key of $params not in $known.
     *
     * @param array<array-key, mixed> $params
     * @param list<string> $known
     * @param string $refusal the message, which the known names and a full stop end
     * @param list<Violation> $violations
     */
    public static function rejectUnknown(array $params, array $known, string $refusal, array &$violations): void
    {
        foreach (array_keys($params) as $key) {
            if (!in_array($key, $known, true)) {
                $violations[] = new Violation([(string) $key], 'unknown_parameter', $refusal . implode(', ', $known) . '.');
            }
        }
    }

    /**
     * $params[$key] as a string, or null when it is absent or empty, or not a single value.
     *
     * @param array<array-key, mixed> $params
     * @param list<Violation> $violations
     */
    public static function string(array $params, string $key, array &$violations): ?string
    {
        $value = $params[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            $violations[] = new Violation([$key], 'type_mismatch', 'must be a single value.');

            return null;
        }

        return $value;
    }

    /**
     * `queue`, when it names a watched queue.
     *
     * @param array<array-key, mixed> $params
     * @param list<Violation> $violations
     */
    public static function queue(array $params, QueueNames $queues, array &$violations): ?string
    {
        $queue = self::string($params, 'queue', $violations);

        if ($queue !== null && !$queues->has($queue)) {
            $violations[] = new Violation(['queue'], 'invalid_choice', 'is not a queue this dashboard watches.', ['choices' => $queues->names]);

            return null;
        }

        return $queue;
    }

    /**
     * `class`, without its leading backslash, when it is a fully-qualified class name.
     *
     * @param array<array-key, mixed> $params
     * @param list<Violation> $violations
     */
    public static function className(array $params, array &$violations): ?string
    {
        $class = self::string($params, 'class', $violations);

        if ($class === null) {
            return null;
        }

        $class = ltrim($class, '\\');

        if (strlen($class) > 255 || preg_match(FailedJobFilter::CLASS_NAME, $class) !== 1) {
            $violations[] = new Violation(['class'], 'invalid_format', 'must be a fully-qualified class name.');

            return null;
        }

        return $class;
    }
}
