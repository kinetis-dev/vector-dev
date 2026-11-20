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
use Kinetis\Vector\Exception\RecordNotFoundException;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\VectorAi\Auth\AiAccessInterface;
use Kinetis\VectorAi\Config\AiConfig;
use Kinetis\VectorAi\Failure\AiFailure;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Governance\ModelBoundary;
use Kinetis\VectorAi\Providers\ProviderResolver;
use Kinetis\VectorAi\Providers\ResolvedProvider;
use Kinetis\VectorAi\Settings\AiSettingsReader;
use Kinetis\VectorAi\Usage\UsageLedger;
use Kinetis\VectorAi\Writing\WritingAssistant;
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
 * text, Markdown or rich-text field (403); one they may write in the
 * view the request is for — `create` without `recordId`, `update` with
 * it, the record read as they may read it and its own `update` decided
 * (403, or 404 for no such record); one ModelBoundary lets a model be
 * sent (403); the feature on, configured and theirs to use (403); the
 * day's token budget (429 until midnight UTC).
 */
#[RoutePrefix('/vector/ai')]
#[Middleware('@vector')]
final readonly class WritingController
{
    /** The largest request body taken: a text of MAX_TEXT characters, escaped. */
    private const int MAX_BODY = 262144;

    /** The field types writing help works on. */
    public const array TYPES = ['string', 'text', 'markdown', 'richtext'];

    public function __construct(
        private CurrentUserInterface $user,
        private AiAccessInterface $access,
        private AiConfig $config,
        private AiSettingsReader $settings,
        private ResourceService $resources,
        private ModelBoundary $boundary,
        private ProviderResolver $resolver,
        private UsageLedger $usage,
        private WritingAssistant $writing,
    ) {}

    #[Post('/writing/{resource}/{field}')]
    public function write(string $resource, string $field, ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->input($request);

        // a password field is never sent, whatever else holds: asked of the compiled field, before this user's view of it
        if (($this->resources->registry()->resource($resource)->field($field)['type'] ?? null) === 'password') {
            throw new AiRequestException('A password field is never sent to AI.', 403);
        }

        $visible = $this->resources->resource($resource);
        $compiled = $visible->field($field) ?? throw new AiRequestException("{$visible->label()} has no field {$field}.", 404);

        if (!in_array($compiled['type'] ?? null, self::TYPES, true)) {
            throw new AiRequestException('Writing help works on text, Markdown and rich-text fields only.', 403);
        }

        $this->authorizeWrite($visible, $compiled, $input['recordId']);

        if (!$this->boundary->allows($visible) || !$this->boundary->sends($visible, $field)) {
            throw new AiRequestException("This field of {$visible->label()} is kept from AI.", 403);
        }

        $provider = $this->provider();

        if ($this->usage->remaining((string) $this->user->id(), $this->settings->int('dailyTokenBudgetPerUser', 0)) <= 0) {
            $midnight = new DateTimeImmutable('tomorrow', new DateTimeZone('UTC'));

            return ErrorResponse::create(429, "Today's AI budget is spent; it renews at midnight UTC.", [
                'Retry-After' => (string) max(1, $midnight->getTimestamp() - time()),
            ]);
        }

        $answer = $this->writing->write($provider, $compiled, $input['operation'], $input['text'], $input['format'], $input['targetLocale'], $input['instructions']);

        return JsonResponse::create($answer, 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Refuses unless this user may write $field in the view the request
     * is for: without a record, a create they may make with the field on
     * its form; with one, that record — read as they may read it — and an
     * update of it they may make with the field on the update form.
     *
     * @param array<string, mixed> $field
     */
    private function authorizeWrite(ResourceDescriptor $visible, array $field, ?string $recordId): void
    {
        $view = $recordId === null ? 'create' : 'update';
        $views = is_array($field['views'] ?? null) ? $field['views'] : [];

        if (($field['readonly'] ?? false) === true || ($field['computed'] ?? null) !== null || !in_array($view, $views, true)) {
            throw new AiRequestException("This field of {$visible->label()} cannot be written on the {$view} form.", 403);
        }

        $permissions = $this->resources->permissions($visible);

        if (($permissions[$view] ?? false) !== true) {
            throw new AiRequestException("This account may not {$view} {$visible->label()}.", 403);
        }

        if ($recordId === null) {
            return;
        }

        try {
            $record = $this->resources->show($visible, $recordId);
        } catch (RecordNotFoundException) {
            throw new AiRequestException("{$visible->label()} has no record {$recordId}.", 404);
        }

        if ($this->resources->recordPermissions($visible, $record)['update'] !== true) {
            throw new AiRequestException("This account may not update this record of {$visible->label()}.", 403);
        }
    }

    /** The feature's provider for this user, or 403 when it is off, unconfigured or not theirs. */
    private function provider(): ResolvedProvider
    {
        if (
            !$this->config->configured()
            || !$this->settings->bool(Feature::Writing->enabledSetting())
            || $this->settings->int('dailyTokenBudgetPerUser', 0) <= 0
            || !$this->access->canUse($this->user, Feature::Writing)
        ) {
            throw new AiRequestException('Writing help is not available to this account.', 403);
        }

        try {
            return $this->resolver->for(Feature::Writing, $this->user);
        } catch (AiFailure $e) {
            throw new AiRequestException('Writing help is not configured: ' . $e->getMessage(), 403);
        }
    }

    /**
     * The body, each member checked: any violation is a 422 naming it.
     *
     * @return array{operation: WritingOperation, text: string, format: 'plain'|'markdown', targetLocale: ?string, instructions: ?string, recordId: ?string}
     */
    private function input(ServerRequestInterface $request): array
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

        $violations = [];
        $operation = is_string($body['operation'] ?? null) ? WritingOperation::tryFrom($body['operation']) : null;

        if ($operation === null) {
            $violations[] = new Violation(['operation'], 'invalid', 'must be one of ' . implode(', ', array_column(WritingOperation::cases(), 'value')) . '.');
        }

        $text = $body['text'] ?? null;

        if (!is_string($text) || trim($text) === '') {
            $violations[] = new Violation(['text'], 'required', 'is required.');
        } elseif (mb_strlen($text) > WritingAssistant::MAX_TEXT) {
            $violations[] = new Violation(['text'], 'too_long', 'must be at most ' . WritingAssistant::MAX_TEXT . ' characters.');
        }

        $format = $body['format'] ?? 'plain';

        if ($format !== 'plain' && $format !== 'markdown') {
            $violations[] = new Violation(['format'], 'invalid', 'must be plain or markdown.');
        }

        $targetLocale = $body['targetLocale'] ?? null;

        if ($operation === WritingOperation::Translate && (!is_string($targetLocale) || preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8}){0,3}$/D', $targetLocale) !== 1)) {
            $violations[] = new Violation(['targetLocale'], 'required', 'is required for translate: a language tag such as de or pt-BR.');
        }

        $instructions = $body['instructions'] ?? null;

        if ($instructions !== null && (!is_string($instructions) || mb_strlen($instructions) > WritingAssistant::MAX_INSTRUCTIONS)) {
            $violations[] = new Violation(['instructions'], 'too_long', 'must be text of at most ' . WritingAssistant::MAX_INSTRUCTIONS . ' characters.');
        }

        $recordId = $body['recordId'] ?? null;

        if ($recordId !== null && (!is_string($recordId) && !is_int($recordId) || (string) $recordId === '' || mb_strlen((string) $recordId) > 64 || preg_match('/[\x00-\x1F\x7F]/', (string) $recordId) === 1)) {
            $violations[] = new Violation(['recordId'], 'invalid', 'must be a record id of at most 64 characters.');
        }

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        $instructions = is_string($instructions) ? trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $instructions)) : null;

        /** @var WritingOperation $operation */
        /** @var string $text */
        /** @var 'plain'|'markdown' $format */
        return [
            'operation' => $operation,
            'text' => $text,
            'format' => $format,
            'targetLocale' => $operation === WritingOperation::Translate && is_string($targetLocale) ? $targetLocale : null,
            'instructions' => $instructions === '' ? null : $instructions,
            'recordId' => $recordId === null ? null : (string) $recordId,
        ];
    }
}
