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

    /** A fully-qualified class name, without its leading backslash; the metrics query accepts the same. */
    public const string CLASS_NAME = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/D';

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
        QueryParameters::rejectUnknown($params, self::PARAMETERS, 'is not a filter; the filters are ', $violations);
        $queue = QueryParameters::queue($params, $queues, $violations);
        $class = QueryParameters::className($params, $violations);
        $from = self::timestamp('failedFrom', QueryParameters::string($params, 'failedFrom', $violations), $violations);
        $to = self::timestamp('failedTo', QueryParameters::string($params, 'failedTo', $violations), $violations);

        if ($from !== null && $to !== null && $from > $to) {
            $violations[] = new Violation(['failedTo'], 'out_of_range', 'must not be before failedFrom.');
        }

        $limit = self::limit(QueryParameters::string($params, 'limit', $violations), $violations);
        $after = self::cursor(QueryParameters::string($params, 'cursor', $violations), $violations);

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

    /**
     * The page size: DEFAULT_LIMIT when $value is absent.
     *
     * @param list<Violation> $violations
     */
    private static function limit(?string $value, array &$violations): int
    {
        if ($value === null) {
            return self::DEFAULT_LIMIT;
        }

        if (preg_match('/^[1-9]\d{0,5}$/D', $value) === 1 && (int) $value <= self::MAX_LIMIT) {
            return (int) $value;
        }

        $violations[] = new Violation(['limit'], 'out_of_range', 'must be a whole number from 1 to ' . self::MAX_LIMIT . '.');

        return self::DEFAULT_LIMIT;
    }

    /**
     * The record a cursor continues after, or null when there is none.
     *
     * @param list<Violation> $violations
     * @return array{failedAt: int, id: string}|null
     */
    private static function cursor(?string $value, array &$violations): ?array
    {
        if ($value === null) {
            return null;
        }

        $after = self::decodeCursor($value);

        if ($after === null) {
            $violations[] = new Violation(['cursor'], 'invalid_format', 'is not a cursor this endpoint returned.');
        }

        return $after;
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
