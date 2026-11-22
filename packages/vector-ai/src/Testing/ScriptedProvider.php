<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Testing;

use Generator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;

/**
 * The `fake` provider type: Neuron's FakeAIProvider replaying the replies
 * a ScriptedReplies keys by the sha256 of the last text block of the last
 * user message, for tests and demos that must not reach a model; a chat,
 * a stream or a structured call alike. It records every request it saw
 * (getRecorded(), from FakeAIProvider), and hands each to the replies'
 * ScriptedCalls when they carry one. A message no reply is scripted for
 * gets "No reply is scripted for this message.". A message whose failure
 * is scripted (ScriptedReplies::failure()) throws it instead, before
 * anything is recorded.
 *
 * A message whose reply is scripted as turns (ScriptedReplies::of())
 * starts a run that calls tools: each call answers with the turn for the
 * rounds of tool results since that message (a ToolCallMessage asking for
 * its `toolCalls`, or text) so the next call sees the results, and the
 * request log keeps every one.
 *
 * Built for one call by ProviderResolver, like every provider.
 */
class ScriptedProvider extends FakeAIProvider
{
    public const string UNSCRIPTED = 'No reply is scripted for this message.';

    public function __construct(private readonly ScriptedReplies $replies = new ScriptedReplies())
    {
        parent::__construct();
    }

    #[\Override]
    public function chat(Message ...$messages): ProviderResponse
    {
        $this->prepare($messages);

        try {
            return parent::chat(...$messages);
        } finally {
            $this->share();
        }
    }

    #[\Override]
    public function stream(Message ...$messages): Generator
    {
        $this->prepare($messages);
        $stream = parent::stream(...$messages);
        $this->share();

        return yield from $stream;
    }

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed> $response_schema
     */
    #[\Override]
    public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
    {
        $this->prepare(is_array($messages) ? array_values($messages) : [$messages]);

        try {
            return parent::structured($messages, $class, $response_schema);
        } finally {
            $this->share();
        }
    }

    /** The last text block of the last user message among $messages (a tool result is none) or '' when there is none. */
    public static function lastUserMessage(Message ...$messages): string
    {
        return self::turnOf(...$messages)[0];
    }

    /**
     * The user message the run answers, and its round: how many rounds of
     * tool results came after it.
     *
     * @return array{string, int}
     */
    private static function turnOf(Message ...$messages): array
    {
        $round = 0;

        foreach (array_reverse($messages) as $message) {
            if ($message instanceof ToolResultMessage) {
                ++$round;
            } elseif ($message->getRole() === 'user') {
                $blocks = $message->getTextBlocks();

                return [$blocks === [] ? '' : end($blocks)->content, $round];
            }
        }

        return ['', $round];
    }

    /** Hands the request just recorded to the replies' ScriptedCalls, when they carry one. */
    private function share(): void
    {
        $recorded = $this->getRecorded();
        $last = end($recorded);

        if ($last !== false) {
            $this->replies->calls?->add($last);
        }
    }

    /** @param array<Message> $messages */
    private function prepare(array $messages): void
    {
        [$text, $round] = self::turnOf(...$messages);
        $failure = $this->replies->failure($text);

        if ($failure !== null) {
            throw $failure;
        }

        $turn = $this->replies->turn($text, $round);
        $reply = match (true) {
            $turn !== null && ($turn['toolCalls'] ?? []) !== [] => new ToolCallMessage($turn['text'] ?? null, array_map(
                static fn (array $call, int $i): ToolCall => new ToolCall($call['name'], "call_{$round}_{$i}", $call['arguments'] ?? []),
                $turn['toolCalls'],
                array_keys($turn['toolCalls']),
            )),
            $turn !== null => new AssistantMessage($turn['text'] ?? ''),
            default => new AssistantMessage($this->replies->replyTo($text) ?? self::UNSCRIPTED),
        };
        $reply->setUsage(new Usage(max(1, intdiv(strlen($text), 4)), max(1, intdiv(strlen(is_string($content = $reply->getContent()) ? $content : (string) json_encode($reply)), 4))));
        $this->addResponses($reply);
    }
}
