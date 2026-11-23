<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Http;

use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Http\Responses\JsonResponse;
use Kinetis\Runtime\ClientConnectionInterface;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\VectorAi\Approvals\ApprovalId;
use Kinetis\VectorAi\Assistant\Assistant;
use Kinetis\VectorAi\Assistant\DepartureSignal;
use Kinetis\VectorAi\Auth\AiAvailability;
use Kinetis\VectorAi\Feature;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /vector/ai/assistant: one turn of the assistant,
 * `{conversationId, messages: [{role, content} | {role: "approval", id}],
 * context?: {path, resource, recordId}}` in. The answer streams as
 * Server-Sent Events in the Vercel AI SDK data-stream protocol, a change
 * the assistant proposed as a `data-approval` frame; with `Accept:
 * application/json` it comes whole, `{text, toolCalls, approvals}`, what
 * the admin falls back to where the runtime cannot stream (RoadRunner
 * answers a stream with 501).
 *
 * The conversation is the browser's: it is replayed on every turn, holds
 * the user's and the assistant's text, and the ids of proposals the admin
 * decided on (`{role: "approval", id: "apr_…"}`), whose outcome the server
 * reads from its own row, and authorizes nothing: every tool runs as this
 * request's user, and nothing in it can execute a change. Asked in this order, before any frame
 * is sent: whether the feature is on, configured and this user's (403,
 * AiAvailability::provider()), the day's token budget (429 until midnight
 * UTC, AiAvailability::budgetSpent()), the body (422).
 */
#[RoutePrefix('/vector/ai')]
#[Middleware('@vector')]
final readonly class AssistantController
{
    /**
     * The largest request body taken, in bytes. The admin trims the
     * conversation it sends to fit it, measured in UTF-8 bytes:
     * `ASSISTANT_MAX_BODY_BYTES` in @kinetis/vector-ui
     * (app/utils/assistantBody.ts), which its test suite checks against
     * this constant.
     */
    public const int MAX_BODY = 65536;

    /** The most messages one turn replays; `ASSISTANT_MAX_MESSAGES` in @kinetis/vector-ui, checked the same way. */
    public const int MAX_MESSAGES = 40;

    /** The longest conversation id. */
    public const int MAX_CONVERSATION_ID = 64;

    /** The longest single message, in characters. */
    public const int MAX_MESSAGE = 8000;

    public function __construct(
        private AiAvailability $availability,
        private Assistant $assistant,
    ) {}

    #[Post('/assistant')]
    public function ask(ServerRequestInterface $request, ClientConnectionInterface $connection): ResponseInterface
    {
        // a streamed answer stops reading the provider once the browser has left, as far as the runtime can
        // tell (Kinetis' runtime-adapters guide lists when); the signal remembers that it was this that stopped it
        $signal = new DepartureSignal($connection);
        $provider = $this->availability->provider(Feature::Assistant, $signal->shouldStop(...));

        if (($spent = $this->availability->budgetSpent()) !== null) {
            return $spent;
        }

        [$conversationId, $conversation, $context] = $this->input($request);
        $instructions = $this->assistant->instructions($context['path'], $context['resource'], $context['recordId']);

        if (self::wantsJson($request)) {
            return JsonResponse::create($this->assistant->answer($provider, $instructions, $conversationId, $conversation, $signal), 200, ['Cache-Control' => 'no-store']);
        }

        return $this->assistant->stream($provider, $connection, $instructions, $conversationId, $conversation, $signal);
    }

    /** Whether the client asked for the whole answer as JSON rather than a stream. */
    private static function wantsJson(ServerRequestInterface $request): bool
    {
        $accept = strtolower($request->getHeaderLine('Accept'));

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/event-stream');
    }

    /**
     * The conversation id, the messages and the page context, each checked:
     * any violation is a 422 naming it.
     *
     * @return array{string, list<array{role: 'user'|'assistant', content: string}|array{role: 'approval', id: string}>, array{path: ?string, resource: ?string, recordId: ?string}}
     */
    private function input(ServerRequestInterface $request): array
    {
        $body = self::body($request);
        $violations = [];
        $conversationId = $body['conversationId'] ?? null;

        if (!is_string($conversationId) || preg_match('/^[A-Za-z0-9_-]{1,' . self::MAX_CONVERSATION_ID . '}$/', $conversationId) !== 1) {
            $violations[] = new Violation(['conversationId'], 'invalid', 'must be 1 to ' . self::MAX_CONVERSATION_ID . ' letters, digits, "-" or "_".');
        }

        $conversation = self::conversation($body['messages'] ?? null, $violations);
        $page = self::page($body['context'] ?? null, $violations);

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        /** @var string $conversationId */
        return [$conversationId, $conversation, $page];
    }

    /**
     * The body as a JSON object: one violation without a path when it is
     * too large, not JSON or not an object.
     *
     * @return array<string, mixed>
     */
    private static function body(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();

        if (strlen($raw) > self::MAX_BODY) {
            throw ValidationException::fromViolations([new Violation([], 'too_large', 'The request body is larger than ' . self::MAX_BODY . ' bytes.')]);
        }

        try {
            $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::fromViolations([new Violation([], 'invalid_json', 'The request body is not valid JSON.')]);
        }

        if (!is_array($body) || array_is_list($body)) {
            throw ValidationException::fromViolations([new Violation([], 'invalid_json', 'The request body must be a JSON object.')]);
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * The messages, each checked, every violation added to $violations;
     * the ending is judged only when there is no other violation so far.
     *
     * @param list<Violation> $violations
     * @return list<array{role: 'user'|'assistant', content: string}|array{role: 'approval', id: string}>
     */
    private static function conversation(mixed $messages, array &$violations): array
    {
        if (!is_array($messages) || !array_is_list($messages) || $messages === [] || count($messages) > self::MAX_MESSAGES) {
            $violations[] = new Violation(['messages'], 'invalid', 'must be a list of 1 to ' . self::MAX_MESSAGES . ' messages.');

            return [];
        }

        $conversation = [];

        foreach ($messages as $i => $message) {
            $turn = self::message($i, $message, $violations);

            if ($turn !== null) {
                $conversation[] = $turn;
            }
        }

        if ($violations === [] && !in_array($conversation[count($conversation) - 1]['role'] ?? null, ['user', 'approval'], true)) {
            $violations[] = new Violation(['messages'], 'invalid', 'must end with the user\'s message or a decided approval.');
        }

        return $conversation;
    }

    /**
     * Message $i of the conversation, or null with its violation added
     * to $violations.
     *
     * @param list<Violation> $violations
     * @return array{role: 'user'|'assistant', content: string}|array{role: 'approval', id: string}|null
     */
    private static function message(int $i, mixed $message, array &$violations): ?array
    {
        $role = is_array($message) ? ($message['role'] ?? null) : null;
        $content = is_array($message) ? ($message['content'] ?? null) : null;

        if ($role === 'approval') {
            $id = $message['id'] ?? null;

            if (!is_string($id) || ApprovalId::parse($id) === null) {
                $violations[] = new Violation(['messages', $i, 'id'], 'invalid', 'must be an approval id, "apr_" and 26 letters or digits.');

                return null;
            }

            return ['role' => 'approval', 'id' => $id];
        }

        if ($role !== 'user' && $role !== 'assistant') {
            $violations[] = new Violation(['messages', $i, 'role'], 'invalid', 'must be "user", "assistant" or "approval".');

            return null;
        }

        if (!is_string($content) || trim($content) === '' || mb_strlen($content) > self::MAX_MESSAGE) {
            $violations[] = new Violation(['messages', $i, 'content'], 'invalid', 'must be text of 1 to ' . self::MAX_MESSAGE . ' characters.');

            return null;
        }

        return ['role' => $role, 'content' => $content];
    }

    /**
     * The page context, each member checked, every violation added to
     * $violations; a context that is not an object is none.
     *
     * @param list<Violation> $violations
     * @return array{path: ?string, resource: ?string, recordId: ?string}
     */
    private static function page(mixed $context, array &$violations): array
    {
        $context = is_array($context) ? $context : [];
        $page = [];

        foreach (['path' => 200, 'resource' => 64, 'recordId' => 64] as $key => $max) {
            $value = $context[$key] ?? null;

            if ($value !== null && (!is_string($value) && !is_int($value) || mb_strlen((string) $value) > $max || preg_match('/[\x00-\x1F\x7F]/', (string) $value) === 1)) {
                $violations[] = new Violation(['context', $key], 'invalid', "must be text of at most {$max} characters.");
                $value = null;
            }

            $page[$key] = $value === null || $value === '' ? null : (string) $value;
        }

        return $page;
    }
}
