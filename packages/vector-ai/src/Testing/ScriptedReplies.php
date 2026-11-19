<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Testing;

/**
 * What a `fake` provider answers, keyed by the sha256 of the last user
 * message's text: an application's test binds one on AppScope, and every
 * ScriptedProvider the resolver builds replays it. Immutable; the default,
 * empty, answers every message with FakeAIProvider's default reply.
 */
final readonly class ScriptedReplies
{
    /**
     * @param array<string, string> $replies sha256 of a user message => the reply
     */
    public function __construct(public array $replies = []) {}

    /**
     * @param array<string, string> $byMessage user message => reply
     */
    public static function of(array $byMessage): self
    {
        $replies = [];

        foreach ($byMessage as $message => $reply) {
            $replies[self::key((string) $message)] = $reply;
        }

        return new self($replies);
    }

    public static function key(string $message): string
    {
        return hash('sha256', $message);
    }

    public function replyTo(string $message): ?string
    {
        return $this->replies[self::key($message)] ?? null;
    }
}
