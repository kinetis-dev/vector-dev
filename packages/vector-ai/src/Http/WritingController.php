<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Http;

use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Http\Responses\JsonResponse;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Exception\RecordNotFoundException;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\VectorAi\Auth\AiAvailability;
use Kinetis\VectorAi\Writing\WritingAssistant;
use Kinetis\VectorAi\Writing\WritingGuard;
use Kinetis\VectorAi\Writing\WritingOperation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /vector/ai/writing/{resource}/{field}: writing help for one
 * field's text, `{operation, text, format?, targetLocale?, instructions?,
 * recordId?}` in, `{text, operation, model, truncated}` out. Nothing is
 * written: the admin puts the text in the input, and the person saves it
 * through Vector's ordinary write path, as their own edit.
 *
 * The body is read first (422). Then, as this request's user and failing
 * closed: the resource (404); a password field, refused by its type
 * before anything else (403); the field as this user sees it (404); a
 * text, Markdown or rich-text field (403); one writing help is on for,
 * not a unique string unless it opts in with `ai.writing` true, nor one
 * with `ai.writing` false (WritingGuard::helps(), 403); one they may write in the
 * view the request is for, `create` without `recordId`, `update` with
 * it, the record read as they may read it and its own `update` decided
 * (403, or 404 for no such record); one ModelBoundary lets a model be
 * sent (403); the feature on, configured and theirs to use (403); the
 * day's token budget (429 until midnight UTC, AiAvailability::budgetSpent()). The rules are
 * WritingGuard's, which the bulk ProposeRewrites action asks too.
 */
#[RoutePrefix('/vector/ai')]
#[Middleware('@vector')]
final readonly class WritingController
{
    /** The largest request body taken: a text of MAX_TEXT characters, escaped. */
    private const int MAX_BODY = 262144;

    /** The field types writing help works on. */
    public const array TYPES = WritingGuard::TYPES;

    public function __construct(
        private ResourceService $resources,
        private WritingGuard $guard,
        private WritingAssistant $writing,
        private AiAvailability $availability,
    ) {}

    #[Post('/writing/{resource}/{field}')]
    public function write(string $resource, string $field, ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->input($request);
        [$visible, $compiled] = $this->guard->field($resource, $field);
        $this->authorizeWrite($visible, $compiled, $input['recordId']);
        $this->guard->sendable($visible, $field);
        $provider = $this->guard->provider();

        if (($spent = $this->availability->budgetSpent()) !== null) {
            return $spent;
        }

        $answer = $this->writing->write($provider, $compiled, $input['operation'], $input['text'], $input['format'], $input['targetLocale'], $input['instructions']);

        return JsonResponse::create($answer, 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Refuses unless this user may write $field in the view the request
     * is for: without a record, a create they may make with the field on
     * its form; with one, that record (read as they may read it) and an
     * update of it they may make with the field on the update form.
     *
     * @param array<string, mixed> $field
     */
    private function authorizeWrite(ResourceDescriptor $visible, array $field, ?string $recordId): void
    {
        $this->guard->writable($visible, $field, $recordId === null ? 'create' : 'update');

        if ($recordId === null) {
            return;
        }

        try {
            $record = $this->resources->show($visible, $recordId);
        } catch (RecordNotFoundException) {
            throw new AiRequestException("{$visible->label()} has no record {$recordId}.", 404);
        }

        if (!$this->guard->mayUpdate($visible, $record)) {
            throw new AiRequestException("This account may not update this record of {$visible->label()}.", 403);
        }
    }

    /**
     * The body, each member checked: any violation is a 422 naming it.
     *
     * @return array{operation: WritingOperation, text: string, format: 'plain'|'markdown', targetLocale: ?string, instructions: ?string, recordId: ?string}
     */
    private function input(ServerRequestInterface $request): array
    {
        $body = self::body($request);
        $operation = is_string($body['operation'] ?? null) ? WritingOperation::tryFrom($body['operation']) : null;
        $text = $body['text'] ?? null;
        $format = $body['format'] ?? 'plain';
        $targetLocale = $body['targetLocale'] ?? null;
        $instructions = $body['instructions'] ?? null;
        $recordId = $body['recordId'] ?? null;
        $violations = [
            ...self::textViolations($operation, $text, $format),
            ...self::optionViolations($operation, $targetLocale, $instructions, $recordId),
        ];

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        $instructions = is_string($instructions) ? WritingAssistant::guidance($instructions) : null;

        /** @var WritingOperation $operation */
        /** @var string $text */
        /** @var 'plain'|'markdown' $format */
        return [
            'operation' => $operation,
            'text' => $text,
            'format' => $format,
            'targetLocale' => $operation === WritingOperation::Translate && is_string($targetLocale) ? $targetLocale : null,
            'instructions' => $instructions,
            'recordId' => $recordId === null ? null : (string) $recordId,
        ];
    }

    /**
     * The body as a JSON object: a 413 when it is too large, one violation
     * without a path when it is not JSON or not an object.
     *
     * @return array<string, mixed>
     */
    private static function body(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();

        if (strlen($raw) > self::MAX_BODY) {
            throw new AiRequestException('The request body is larger than ' . self::MAX_BODY . ' bytes.', 413);
        }

        try {
            $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
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
     * The violations of the operation, the text and its format, in that order.
     *
     * @return list<Violation>
     */
    private static function textViolations(?WritingOperation $operation, mixed $text, mixed $format): array
    {
        $violations = [];

        if ($operation === null) {
            $violations[] = new Violation(['operation'], 'invalid', 'must be one of ' . implode(', ', array_column(WritingOperation::cases(), 'value')) . '.');
        }

        if (!is_string($text) || trim($text) === '') {
            $violations[] = new Violation(['text'], 'required', 'is required.');
        } elseif (mb_strlen($text) > WritingAssistant::MAX_TEXT) {
            $violations[] = new Violation(['text'], 'too_long', 'must be at most ' . WritingAssistant::MAX_TEXT . ' characters.');
        }

        if ($format !== 'plain' && $format !== 'markdown') {
            $violations[] = new Violation(['format'], 'invalid', 'must be plain or markdown.');
        }

        return $violations;
    }

    /**
     * The violations of the target language, the instructions and the
     * record id, in that order.
     *
     * @return list<Violation>
     */
    private static function optionViolations(?WritingOperation $operation, mixed $targetLocale, mixed $instructions, mixed $recordId): array
    {
        $violations = [];

        if ($operation === WritingOperation::Translate && (!is_string($targetLocale) || !WritingOperation::isLocale($targetLocale))) {
            $violations[] = new Violation(['targetLocale'], 'required', 'is required for translate: a language tag such as de or pt-BR.');
        }

        if ($instructions !== null && (!is_string($instructions) || mb_strlen($instructions) > WritingAssistant::MAX_INSTRUCTIONS)) {
            $violations[] = new Violation(['instructions'], 'too_long', 'must be text of at most ' . WritingAssistant::MAX_INSTRUCTIONS . ' characters.');
        }

        if ($recordId !== null && (!is_string($recordId) && !is_int($recordId) || (string) $recordId === '' || mb_strlen((string) $recordId) > 64 || preg_match('/[\x00-\x1F\x7F]/', (string) $recordId) === 1)) {
            $violations[] = new Violation(['recordId'], 'invalid', 'must be a record id of at most 64 characters.');
        }

        return $violations;
    }
}
