<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues;

use DateTimeImmutable;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;

/**
 * One validated `GET /vector/queues/failed` query: `queue` (a watched
 * queue), `class` (a fully-qualified class name), `failedFrom` and
 * `failedTo` (RFC 3339, whole seconds, both inclusive), `limit` (1 to
 * MAX_LIMIT, DEFAULT_LIMIT when absent) and `cursor` (the `nextCursor`
 * of the previous page, opaque to the client). Any other parameter, and
 * any bad value, is a 422 naming it.
 */
final readonly class FailedJobFilter
{
    public const int DEFAULT_LIMIT = 25;

    public const int MAX_LIMIT = 100;

    private const array PARAMETERS = ['queue', 'class', 'failedFrom', 'failedTo', 'limit', 'cursor'];

    private const string CLASS_NAME = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/D';

    private const string TIMESTAMP = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(Z|[+-]\d{2}:\d{2})$/D';

    private const string ID = '/^[0-9a-f]{32}$/D';

    /** @param array{failedAt: int, id: string}|null $after */
    private function __construct(
        public ?string $queue,
        public ?string $class,
        public ?int $failedFrom,
        public ?int $failedTo,
        public int $limit,
        public ?array $after,
    ) {}

    /** @param array<array-key, mixed> $params */
    public static function fromQueryParams(array $params, QueueNames $queues): self
    {
        $violations = [];

        foreach (array_keys($params) as $key) {
            if (!in_array($key, self::PARAMETERS, true)) {
                $violations[] = new Violation([(string) $key], 'unknown_parameter', 'is not a filter; the filters are ' . implode(', ', self::PARAMETERS) . '.');
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

            if (strlen($class) > 255 || preg_match(self::CLASS_NAME, $class) !== 1) {
                $violations[] = new Violation(['class'], 'invalid_format', 'must be a fully-qualified class name.');
                $class = null;
            }
        }

        $from = self::timestamp('failedFrom', $string('failedFrom'), $violations);
        $to = self::timestamp('failedTo', $string('failedTo'), $violations);

        if ($from !== null && $to !== null && $from > $to) {
            $violations[] = new Violation(['failedTo'], 'out_of_range', 'must not be before failedFrom.');
        }

        $limit = self::DEFAULT_LIMIT;
        $rawLimit = $string('limit');

        if ($rawLimit !== null) {
            if (preg_match('/^[1-9]\d{0,5}$/D', $rawLimit) !== 1 || (int) $rawLimit > self::MAX_LIMIT) {
                $violations[] = new Violation(['limit'], 'out_of_range', 'must be a whole number from 1 to ' . self::MAX_LIMIT . '.');
            } else {
                $limit = (int) $rawLimit;
            }
        }

        $after = null;
        $cursor = $string('cursor');

        if ($cursor !== null) {
            $after = self::decodeCursor($cursor);

            if ($after === null) {
                $violations[] = new Violation(['cursor'], 'invalid_format', 'is not a cursor this endpoint returned.');
            }
        }

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        return new self($queue, $class, $from, $to, $limit, $after);
    }

    /** The opaque cursor for the page after the one ending at this record. */
    public static function encodeCursor(int $failedAt, string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode([$failedAt, $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{failedAt: int, id: string}|null */
    private static function decodeCursor(string $cursor): ?array
    {
        if (strlen($cursor) > 128) {
            return null;
        }

        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $decoded = is_string($json) ? json_decode($json, true, 2) : null;

        if (!is_array($decoded) || !array_is_list($decoded) || count($decoded) !== 2) {
            return null;
        }

        [$failedAt, $id] = $decoded;

        return is_int($failedAt) && $failedAt >= 0 && is_string($id) && preg_match(self::ID, $id) === 1
            ? ['failedAt' => $failedAt, 'id' => $id]
            : null;
    }

    /** @param list<Violation> $violations */
    private static function timestamp(string $key, ?string $value, array &$violations): ?int
    {
        if ($value === null) {
            return null;
        }

        $match = [];

        if (preg_match(self::TIMESTAMP, $value, $match) === 1) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $match[1] . ($match[2] === 'Z' ? '+00:00' : $match[2]));

            // a date PHP would roll over (February 30th) is a parse warning, and refused
            if ($parsed !== false && DateTimeImmutable::getLastErrors() === false) {
                return $parsed->getTimestamp();
            }
        }

        $violations[] = new Violation([$key], 'invalid_format', 'must be an RFC 3339 date and time in whole seconds, such as 2026-11-02T09:30:00Z.');

        return null;
    }
}
