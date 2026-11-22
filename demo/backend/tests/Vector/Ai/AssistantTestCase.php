<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Http\Kernel;
use Kinetis\Http\StreamedResponse;
use Kinetis\Testing\TestClient;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Testing\RequestRecord;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;

/**
 * The assistant against the `fake` provider (FiltersTestCase's setup):
 * turn() sends one turn through the kernel and, for a stream, emits it
 * as the runtime would and reads its frames back; script() binds what the
 * fake answers, its tool-calling turns included.
 */
abstract class AssistantTestCase extends FiltersTestCase
{
    /** The sentence the scripted runs answer. */
    protected const string QUESTION = 'Which products are low on stock?';

    /**
     * One turn: $messages (strings alternate user and assistant, the last
     * the user's), or the whole body when $body is given.
     *
     * @param list<string> $messages
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @return array{status: int, response: ResponseInterface, body: string, frames: list<array<string, mixed>>}
     */
    protected function turn(array $messages = [self::QUESTION], ?array $body = null, array $headers = [], int $actor = 1, ?string $raw = null): array
    {
        $body ??= [
            'conversationId' => 'c_test',
            'messages' => array_map(static fn (string $m, int $i): array => ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => $m], $messages, array_keys($messages)),
            'context' => ['path' => '/r/products', 'resource' => 'products', 'recordId' => null],
        ];
        $request = new ServerRequest('POST', '/vector/ai/assistant', ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream', ...$this->aiHeaders($actor), ...$headers]);
        $request = $request->withBody(Stream::create($raw ?? json_encode($body, JSON_THROW_ON_ERROR)));

        // the test client's own kernel: its TestResponse hides a stream's emitter, which this reads
        $kernel = (new \ReflectionProperty(TestClient::class, 'kernel'))->getValue($this->client);
        self::assertInstanceOf(Kernel::class, $kernel);
        $response = $kernel->handle($request);
        $text = $response instanceof StreamedResponse ? self::emit($response) : (string) $response->getBody();

        return ['status' => $response->getStatusCode(), 'response' => $response, 'body' => $text, 'frames' => self::frames($text)];
    }

    /** What a streamed response writes, as the runtime would emit it, kept from the test's own output. */
    protected static function emit(StreamedResponse $response): string
    {
        $written = '';
        ob_start(static function (string $chunk) use (&$written): string {
            $written .= $chunk;

            return '';
        });

        try {
            ($response->getEmitter())();
        } finally {
            ob_end_flush();
        }

        return $written;
    }

    /**
     * The `data:` frames of an event stream, each decoded.
     *
     * @return list<array<string, mixed>>
     */
    protected static function frames(string $stream): array
    {
        $frames = [];

        foreach (explode("\n\n", $stream) as $block) {
            if (!str_starts_with($block, 'data: ')) {
                continue;
            }

            $frame = json_decode(substr($block, 6), true, 64, JSON_THROW_ON_ERROR);
            self::assertIsArray($frame);
            $frames[] = $frame;
        }

        return $frames;
    }

    /**
     * The frames of $type.
     *
     * @param list<array<string, mixed>> $frames
     * @return list<array<string, mixed>>
     */
    protected static function ofType(array $frames, string $type): array
    {
        return array_values(array_filter($frames, static fn (array $f): bool => $f['type'] === $type));
    }

    /**
     * @param list<array<string, mixed>> $frames
     * @return array<string, mixed> the last frame
     */
    protected static function last(array $frames): array
    {
        $last = end($frames);
        self::assertIsArray($last, 'no frame');

        return $last;
    }

    /** @param list<array<string, mixed>> $frames the answer's text, its deltas joined */
    protected static function text(array $frames): string
    {
        return implode('', array_map(static fn (array $f): string => (string) $f['delta'], self::ofType($frames, 'text-delta')));
    }

    /**
     * The tool results a recorded call carried to the model, in order.
     *
     * @return list<string>
     */
    protected static function toolResults(RequestRecord $call): array
    {
        $results = [];

        foreach ($call->messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $tool) {
                    $results[] = (string) $tool->getResult();
                }
            }
        }

        return $results;
    }

    /** Everything a recorded call carried, tool results included, as one text. */
    protected static function everything(RequestRecord $call): string
    {
        return self::sent($call) . "\n" . implode("\n", self::toolResults($call));
    }

    /**
     * A scripted run: the tool calls of each round, then the answer.
     *
     * @param list<list<array{name: string, arguments?: array<string, mixed>}>> $rounds
     * @return list<array{text?: string, toolCalls?: list<array{name: string, arguments?: array<string, mixed>}>}>
     */
    protected static function rounds(array $rounds, string $answer): array
    {
        return [...array_map(static fn (array $calls): array => ['toolCalls' => $calls], $rounds), ['text' => $answer]];
    }

    /** @return list<array<string, mixed>> this actor's usage rows, oldest first */
    protected function usageRows(int $actor): array
    {
        return $this->vectorQuery()->table('vector_ai_usage')->where('actor_id', '=', self::ACTOR_PREFIX . str_pad((string) $actor, 2, '0', STR_PAD_LEFT))->orderBy('id', 'ASC')->get();
    }
}
