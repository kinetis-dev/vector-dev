<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Testing;

/**
 * What a `fake` provider answers, keyed by the sha256 of the last text
 * block of the last user message — for a one-block message, its whole
 * text; for a filter request, the admin's sentence, which follows the
 * resource's schema. An application's test binds one on AppScope, and
 * every ScriptedProvider the resolver builds replays it. The default,
 * empty, answers every message with ScriptedProvider::UNSCRIPTED.
 *
 * A test that needs to see what was sent gives it a ScriptedCalls: each
 * call any of its providers answers is appended there. It is the test's
 * own object; nothing outside a test binds one.
 */
final readonly class ScriptedReplies
{
    /**
     * @param array<string, string> $replies sha256 of a user message => the reply
     */
    public function __construct(public array $replies = [], public ?ScriptedCalls $calls = null) {}

    /**
     * @param array<string, string> $byMessage user message => reply
     */
    public static function of(array $byMessage, ?ScriptedCalls $calls = null): self
    {
        $replies = [];

        foreach ($byMessage as $message => $reply) {
            $replies[self::key((string) $message)] = $reply;
        }

        return new self($replies, $calls);
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
