<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Testing;

use Generator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;

/**
 * The `fake` provider type: Neuron's FakeAIProvider replaying the replies
 * a ScriptedReplies keys by the sha256 of the last user message, for
 * tests and demos that must not reach a model. It records every request
 * it saw (getRecorded(), from FakeAIProvider). A message no reply is
 * scripted for gets "No reply is scripted for this message.".
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

        return parent::chat(...$messages);
    }

    #[\Override]
    public function stream(Message ...$messages): Generator
    {
        $this->prepare($messages);

        return yield from parent::stream(...$messages);
    }

    /** The text of the last user message among $messages, or '' when there is none. */
    public static function lastUserMessage(Message ...$messages): string
    {
        foreach (array_reverse($messages) as $message) {
            if ($message->getRole() === 'user') {
                return (string) $message->getContent();
            }
        }

        return '';
    }

    /** @param array<Message> $messages */
    private function prepare(array $messages): void
    {
        $text = self::lastUserMessage(...$messages);
        $reply = new AssistantMessage($this->replies->replyTo($text) ?? self::UNSCRIPTED);
        $reply->setUsage(new Usage(max(1, intdiv(strlen($text), 4)), max(1, intdiv(strlen((string) $reply->getContent()), 4))));
        $this->addResponses($reply);
    }
}
