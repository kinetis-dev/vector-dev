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
 * A message can instead start a run that calls tools (of(), turn()).
 *
 * A test that needs to see what was sent gives it a ScriptedCalls: each
 * call any of its providers answers is appended there. It is the test's
 * own object; nothing outside a test binds one.
 */
final readonly class ScriptedReplies
{
    /**
     * @param array<string, string> $replies sha256 of a user message => the reply
     * @param array<string, list<array{text?: string, toolCalls?: list<array{name: string, arguments?: array<string, mixed>}>}>> $turns
     *     sha256 of a user message => the turns of a run that uses tools, in order
     */
    public function __construct(public array $replies = [], public ?ScriptedCalls $calls = null, public array $turns = []) {}

    /**
     * A message's reply is either its text, or the turns of a tool-using
     * run: each turn answers with text, or asks for `toolCalls` (each a
     * tool's `name` and its `arguments`), and the next turn is the reply
     * once those calls' results are in. A run past its last turn gets
     * ScriptedProvider::UNSCRIPTED.
     *
     * @param array<string, string|list<array{text?: string, toolCalls?: list<array{name: string, arguments?: array<string, mixed>}>}>> $byMessage user message => reply
     */
    public static function of(array $byMessage, ?ScriptedCalls $calls = null): self
    {
        $replies = [];
        $turns = [];

        foreach ($byMessage as $message => $reply) {
            if (is_string($reply)) {
                $replies[self::key((string) $message)] = $reply;
            } else {
                $turns[self::key((string) $message)] = $reply;
            }
        }

        return new self($replies, $calls, $turns);
    }

    /**
     * Turn $round (0 for the first call, 1 once the first tool results are
     * in, and so on) of the run $message started, when it is scripted.
     *
     * @return array{text?: string, toolCalls?: list<array{name: string, arguments?: array<string, mixed>}>}|null
     */
    public function turn(string $message, int $round): ?array
    {
        return $this->turns[self::key($message)][$round] ?? null;
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
