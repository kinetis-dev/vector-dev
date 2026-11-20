<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Http;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Http\Responses\JsonResponse;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\VectorAi\Approvals\ApprovalId;
use Kinetis\VectorAi\Assistant\Assistant;
use Kinetis\VectorAi\Auth\AiAccessInterface;
use Kinetis\VectorAi\Config\AiConfig;
use Kinetis\VectorAi\Failure\AiFailure;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Providers\ProviderResolver;
use Kinetis\VectorAi\Providers\ResolvedProvider;
use Kinetis\VectorAi\Settings\AiSettingsReader;
use Kinetis\VectorAi\Usage\UsageLedger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /vector/ai/assistant: one turn of the assistant,
 * `{conversationId, messages: [{role, content} | {role: "approval", id}],
 * context?: {path, resource, recordId}}` in. The answer streams as
 * Server-Sent Events in the Vercel AI SDK data-stream protocol, a change
 * the assistant proposed as a `data-approval` frame; with `Accept:
 * application/json` it comes whole, `{text, toolCalls, approvals}` — what
 * the admin falls back to where the runtime cannot stream (RoadRunner
 * answers a stream with 501).
 *
 * The conversation is the browser's: it is replayed on every turn, holds
 * the user's and the assistant's text, and the ids of proposals the admin
 * decided on (`{role: "approval", id: "apr_…"}`), whose outcome the server
 * reads from its own row — and authorizes nothing: every tool runs as this
 * request's user, and nothing in it can execute a change. Asked in this order, before any frame
 * is sent: whether the feature is on, configured and this user's (403),
 * the day's token budget (429 until midnight UTC), the body (422).
 */
#[RoutePrefix('/vector/ai')]
#[Middleware('@vector')]
final readonly class AssistantController
{
    /** The largest request body taken. */
    public const int MAX_BODY = 65536;

    /** The most messages one turn replays. */
    public const int MAX_MESSAGES = 40;

    /** The longest conversation id. */
    public const int MAX_CONVERSATION_ID = 64;

    /** The longest single message, in characters. */
    public const int MAX_MESSAGE = 8000;

    public function __construct(
        private CurrentUserInterface $user,
        private AiAccessInterface $access,
        private AiConfig $config,
        private AiSettingsReader $settings,
        private ProviderResolver $resolver,
        private UsageLedger $usage,
        private Assistant $assistant,
    ) {}

    #[Post('/assistant')]
    public function ask(ServerRequestInterface $request): ResponseInterface
    {
        $provider = $this->provider();
        $budget = $this->settings->int('dailyTokenBudgetPerUser', 0);

        if ($this->usage->remaining((string) $this->user->id(), $budget) <= 0) {
            $midnight = new DateTimeImmutable('tomorrow', new DateTimeZone('UTC'));

            return ErrorResponse::create(429, "Today's AI budget is spent; it renews at midnight UTC.", [
                'Retry-After' => (string) max(1, $midnight->getTimestamp() - time()),
            ]);
        }

        [$conversationId, $conversation, $context] = $this->input($request);
        $instructions = $this->assistant->instructions($context['path'], $context['resource'], $context['recordId']);

        if (self::wantsJson($request)) {
            return JsonResponse::create($this->assistant->answer($provider, $instructions, $conversationId, $conversation), 200, ['Cache-Control' => 'no-store']);
        }

        return $this->assistant->stream($provider, $instructions, $conversationId, $conversation);
    }

    /** The feature's provider for this user, or 403 when it is off, unconfigured or not theirs. */
    private function provider(): ResolvedProvider
    {
        if (
            !$this->config->configured()
            || !$this->settings->bool(Feature::Assistant->enabledSetting())
            || $this->settings->int('dailyTokenBudgetPerUser', 0) <= 0
            || !$this->access->canUse($this->user, Feature::Assistant)
        ) {
            throw new AiRequestException('The assistant is not available to this account.', 403);
        }

        try {
            // a streamed answer stops reading the provider once the browser has left;
            // PHP knows it at the first write after, and only where the runtime reports it
            return $this->resolver->for(Feature::Assistant, $this->user, static fn (): bool => connection_aborted() === 1);
        } catch (AiFailure $e) {
            throw new AiRequestException('The assistant is not configured: ' . $e->getMessage(), 403);
        }
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

        $violations = [];
        $conversationId = $body['conversationId'] ?? null;

        if (!is_string($conversationId) || preg_match('/^[A-Za-z0-9_-]{1,' . self::MAX_CONVERSATION_ID . '}$/', $conversationId) !== 1) {
            $violations[] = new Violation(['conversationId'], 'invalid', 'must be 1 to ' . self::MAX_CONVERSATION_ID . ' letters, digits, "-" or "_".');
        }

        $conversation = [];
        $messages = $body['messages'] ?? null;

        if (!is_array($messages) || !array_is_list($messages) || $messages === [] || count($messages) > self::MAX_MESSAGES) {
            $violations[] = new Violation(['messages'], 'invalid', 'must be a list of 1 to ' . self::MAX_MESSAGES . ' messages.');
        } else {
            foreach ($messages as $i => $message) {
                $role = is_array($message) ? ($message['role'] ?? null) : null;
                $content = is_array($message) ? ($message['content'] ?? null) : null;

                if ($role === 'approval') {
                    $id = $message['id'] ?? null;

                    if (!is_string($id) || ApprovalId::parse($id) === null) {
                        $violations[] = new Violation(['messages', $i, 'id'], 'invalid', 'must be an approval id, "apr_" and 26 letters or digits.');
                    } else {
                        $conversation[] = ['role' => 'approval', 'id' => $id];
                    }
                } elseif ($role !== 'user' && $role !== 'assistant') {
                    $violations[] = new Violation(['messages', $i, 'role'], 'invalid', 'must be "user", "assistant" or "approval".');
                } elseif (!is_string($content) || trim($content) === '' || mb_strlen($content) > self::MAX_MESSAGE) {
                    $violations[] = new Violation(['messages', $i, 'content'], 'invalid', 'must be text of 1 to ' . self::MAX_MESSAGE . ' characters.');
                } else {
                    $conversation[] = ['role' => $role, 'content' => $content];
                }
            }

            if ($violations === [] && !in_array($conversation[count($conversation) - 1]['role'] ?? null, ['user', 'approval'], true)) {
                $violations[] = new Violation(['messages'], 'invalid', 'must end with the user\'s message or a decided approval.');
            }
        }

        $context = is_array($body['context'] ?? null) ? $body['context'] : [];
        $page = [];

        foreach (['path' => 200, 'resource' => 64, 'recordId' => 64] as $key => $max) {
            $value = $context[$key] ?? null;

            if ($value !== null && (!is_string($value) && !is_int($value) || mb_strlen((string) $value) > $max || preg_match('/[\x00-\x1F\x7F]/', (string) $value) === 1)) {
                $violations[] = new Violation(['context', $key], 'invalid', "must be text of at most {$max} characters.");
                $value = null;
            }

            $page[$key] = $value === null || $value === '' ? null : (string) $value;
        }

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        /** @var string $conversationId */
        return [$conversationId, $conversation, $page];
    }
}
