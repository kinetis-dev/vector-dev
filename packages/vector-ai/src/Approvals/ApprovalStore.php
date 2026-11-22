<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Approvals;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Database\VectorDatabase;

/**
 * `vector_ai_approvals`, on Vector's own connection: the changes the
 * assistant proposed and where each stands. The row is the whole state
 * of a proposal (nothing about it is kept in the worker) and every
 * change of status is one conditional UPDATE on the status it leaves, so
 * two requests deciding the same row cannot both win. Built per request.
 */
final readonly class ApprovalStore
{
    public const string TABLE = 'vector_ai_approvals';

    /**
     * How long a row may stay `executing` before failStale() takes the
     * request running it for dead: far longer than any one write takes.
     */
    public const int EXECUTING_MINUTES = 10;

    /**
     * How deep stored arguments and results may nest, as json_decode()
     * counts it: what reading a row back allows, and so what storable()
     * lets a proposal be saved with and settle() a result; one that read could not decode
     * could never be approved or rejected.
     */
    public const int MAX_DEPTH = 64;

    /** What a result nested deeper than MAX_DEPTH is stored as instead (settle()). */
    public const array TOO_DEEP = ['truncated' => 'too_deep'];

    public function __construct(private VectorDatabase $db) {}

    /**
     * Whether $arguments can be stored and read back: encoded as propose()
     * encodes them, then decoded as a row is read, at MAX_DEPTH.
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function storable(array $arguments): bool
    {
        try {
            json_decode(self::encode($arguments), true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return true;
    }

    /**
     * Stores a pending proposal that expires $minutes from now.
     *
     * @param array<string, mixed> $arguments
     * @throws \InvalidArgumentException when $arguments are not storable(): nesting deeper than MAX_DEPTH
     */
    public function propose(string $actorId, ?string $impersonatorId, string $conversationId, string $tool, array $arguments, int $minutes): Approval
    {
        if (!self::storable($arguments)) {
            throw new \InvalidArgumentException('The arguments nest deeper than ' . self::MAX_DEPTH . ' levels: a stored proposal could not be read back.');
        }

        $now = self::now();
        $encoded = self::encode($arguments);
        $approval = new Approval(
            ApprovalId::generate(),
            $actorId,
            $impersonatorId,
            $conversationId,
            $tool,
            $arguments,
            ApprovalStatus::Pending,
            null,
            $now,
            $now->modify('+' . max(1, $minutes) . ' minutes'),
        );

        $this->query()->insert([
            'id' => $approval->id,
            'actor_id' => $actorId,
            'impersonator_id' => $impersonatorId,
            'conversation_id' => $conversationId,
            'tool' => $tool,
            'arguments' => $encoded,
            'arguments_hash' => hash('sha256', $encoded),
            'status' => ApprovalStatus::Pending->value,
            'created_at' => self::format($approval->createdAt),
            'expires_at' => self::format($approval->expiresAt),
        ]);

        return $approval;
    }

    /**
     * The approval $publicId names when it is $actorId's, proposed under
     * the same impersonator (none, or the same one) as now; otherwise
     * null, whether it does not exist or is someone else's.
     */
    public function owned(string $publicId, string $actorId, ?string $impersonatorId): ?Approval
    {
        $id = ApprovalId::parse($publicId);

        if ($id === null) {
            return null;
        }

        $row = $this->query()->where('id', '=', $id)->where('actor_id', '=', $actorId)->first();

        if (!is_array($row)) {
            return null;
        }

        $approval = self::approval($row);

        // null-safe: an impersonation that ended cannot decide what it proposed, nor the other way round
        return $approval->impersonatorId === $impersonatorId ? $approval : null;
    }

    /** The row as it is now, or null when it is gone. */
    public function fresh(string $id): ?Approval
    {
        $row = $this->query()->where('id', '=', $id)->first();

        return is_array($row) ? self::approval($row) : null;
    }

    /**
     * Moves a pending row to $to; whether this call did it, false when it
     * was no longer pending, or, for any $to but `expired`, when it is past
     * its expiry: the same UPDATE asks both, so a proposal that expires
     * between the caller's check and this cannot be approved or rejected.
     */
    public function decide(string $id, ApprovalStatus $to): bool
    {
        $now = self::format(self::now());
        $query = $this->query()
            ->where('id', '=', $id)
            ->where('status', '=', ApprovalStatus::Pending->value);

        if ($to !== ApprovalStatus::Expired) {
            $query = $query->where('expires_at', '>=', $now);
        }

        return $query->update(['status' => $to->value, 'decided_at' => $now]) === 1;
    }

    /**
     * Fails every row still `executing` that was approved before $before
     * (only $id when given) with Approval::UNKNOWN_OUTCOME: its request did
     * not settle it (the worker died, or the request was cut off), so
     * whether the change was made is unknown. One conditional UPDATE, which
     * never executes anything again; how many it failed. Callers pass
     * now minus EXECUTING_MINUTES.
     */
    public function failStale(DateTimeImmutable $before, ?string $id = null): int
    {
        $query = $this->query()
            ->where('status', '=', ApprovalStatus::Executing->value)
            ->where('decided_at', '<', self::format($before));

        if ($id !== null) {
            $query = $query->where('id', '=', $id);
        }

        return $query->update([
            'status' => ApprovalStatus::Failed->value,
            'result' => json_encode(['error' => Approval::UNKNOWN_OUTCOME], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** The bound of failStale() as of now: the moment before which an `executing` row is taken for dead. */
    public static function staleBefore(): DateTimeImmutable
    {
        return self::now()->modify('-' . self::EXECUTING_MINUTES . ' minutes');
    }

    /**
     * Records how an execution ended: `executed` with the result, or `failed`
     * with why. Returns the result as it is stored (TOO_DEEP in place of one
     * too deep to read back) so the approve response says what every later
     * read does. The row is written only while it is still `executing`: when
     * failStale() took it first (an execution past EXECUTING_MINUTES), the
     * row keeps its unknown-outcome failure and the value returned here is
     * what would have been stored.
     */
    public function settle(string $id, ApprovalStatus $status, mixed $result): mixed
    {
        $encoded = self::encodeResult($result);
        $this->query()
            ->where('id', '=', $id)
            ->where('status', '=', ApprovalStatus::Executing->value)
            ->update([
                'status' => $status->value,
                'result' => $encoded,
                'executed_at' => self::format(self::now()),
            ]);

        return json_decode($encoded, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
    }

    /** Marks every pending row past its expiry `expired`; how many. */
    public function expireStale(DateTimeImmutable $now): int
    {
        return $this->query()
            ->where('status', '=', ApprovalStatus::Pending->value)
            ->where('expires_at', '<', self::format($now))
            ->update(['status' => ApprovalStatus::Expired->value, 'decided_at' => self::format($now)]);
    }

    /** Deletes every decided row decided before $before; how many. */
    public function deleteDecidedBefore(DateTimeImmutable $before): int
    {
        return $this->query()
            ->whereIn('status', array_map(static fn (ApprovalStatus $s): string => $s->value, ApprovalStatus::decided()))
            ->where('decided_at', '<', self::format($before))
            ->delete();
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $row */
    private static function approval(array $row): Approval
    {
        $arguments = json_decode((string) $row['arguments'], true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        $result = $row['result'] === null ? null : json_decode((string) $row['result'], true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        $utc = new DateTimeZone('UTC');

        return new Approval(
            (string) $row['id'],
            (string) $row['actor_id'],
            $row['impersonator_id'] === null ? null : (string) $row['impersonator_id'],
            (string) $row['conversation_id'],
            (string) $row['tool'],
            is_array($arguments) ? $arguments : [],
            ApprovalStatus::from((string) $row['status']),
            $result,
            new DateTimeImmutable((string) $row['created_at'], $utc),
            new DateTimeImmutable((string) $row['expires_at'], $utc),
        );
    }

    /**
     * $result as stored: as it was, when a row can read it back at
     * MAX_DEPTH; otherwise TOO_DEEP in its place; a record with deeply
     * nested JSON makes a result no read could decode, and the change was
     * made all the same: the approval settles, and every later read of the
     * conversation still works.
     */
    private static function encodeResult(mixed $result): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        try {
            $encoded = json_encode($result, $flags);
            json_decode($encoded, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);

            return $encoded;
        } catch (\JsonException $e) {
            if ($e->getCode() !== JSON_ERROR_DEPTH) {
                throw $e;
            }

            return json_encode(self::TOO_DEEP, $flags);
        }
    }

    /** @param array<array-key, mixed> $arguments */
    private static function encode(array $arguments): string
    {
        return json_encode((object) $arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function format(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function query(): Query
    {
        return $this->db->query()->table(self::TABLE);
    }
}
