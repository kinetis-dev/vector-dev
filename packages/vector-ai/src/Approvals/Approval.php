<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Approvals;

use DateTimeImmutable;

/**
 * One row of `vector_ai_approvals`: a change the assistant proposed (a
 * write tool and the arguments it would be called with) for one actor
 * (and impersonator), and where it stands.
 */
final readonly class Approval
{
    /**
     * Why a row left `executing` without its request settling it (the
     * worker died, or the request was cut off) is failed with
     * (ApprovalStore::failStale()): the change may or may not have been made.
     */
    public const string UNKNOWN_OUTCOME = 'The outcome is unknown: the request that ran it did not finish. Check the record\'s History.';

    /**
     * What an execution that threw is failed with: never the exception's
     * text, and never "nothing changed"; the write may have committed
     * before the exception was thrown.
     */
    public const string MAY_HAVE_CHANGED = 'The change failed, but it may have been made before it did. Check the record\'s History.';

    /**
     * @param array<string, mixed> $arguments the arguments the model proposed, exactly as the approval will pass them
     */
    public function __construct(
        public string $id,
        public string $actorId,
        public ?string $impersonatorId,
        public string $conversationId,
        public string $tool,
        public array $arguments,
        public ApprovalStatus $status,
        public mixed $result,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
    ) {}

    /** The id as the model, the stream and the endpoints name it: `apr_…`. */
    public function publicId(): string
    {
        return ApprovalId::public($this->id);
    }

    public function expired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt < $now;
    }

    /** Whether it failed in a way that leaves unknown whether the change was made: UNKNOWN_OUTCOME or MAY_HAVE_CHANGED. */
    public function outcomeUnknown(): bool
    {
        $error = is_array($this->result) ? ($this->result['error'] ?? null) : null;

        return $this->status === ApprovalStatus::Failed && in_array($error, [self::UNKNOWN_OUTCOME, self::MAY_HAVE_CHANGED], true);
    }

    /** The resource the change is to. */
    public function resource(): ?string
    {
        $resource = $this->arguments['resource'] ?? null;

        return is_string($resource) ? $resource : null;
    }

    /** The record the change is to, for an update or a delete; null for a create or an action, whose ids are among its arguments. */
    public function recordId(): ?string
    {
        $id = $this->arguments['id'] ?? null;

        return in_array($this->tool, ['vector_update', 'vector_delete'], true) && (is_string($id) || is_int($id)) ? (string) $id : null;
    }

    /**
     * What the stream and the JSON answer tell the admin of it: the
     * structured change, never anything the model wrote about it.
     *
     * @return array{type: 'approval', id: string, tool: string, arguments: object, resource: ?string, recordId: ?string, expiresAt: string}
     */
    public function frame(): array
    {
        return [
            'type' => 'approval',
            'id' => $this->publicId(),
            'tool' => $this->tool,
            // an object even when empty
            'arguments' => (object) $this->arguments,
            'resource' => $this->resource(),
            'recordId' => $this->recordId(),
            'expiresAt' => self::iso($this->expiresAt),
        ];
    }

    public static function iso(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
