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
use Kinetis\Vector\Query\ResourceService;
use Kinetis\VectorAi\Auth\AiAccessInterface;
use Kinetis\VectorAi\Config\AiConfig;
use Kinetis\VectorAi\Failure\AiFailure;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Filters\FilterTranslator;
use Kinetis\VectorAi\Governance\ModelBoundary;
use Kinetis\VectorAi\Providers\ProviderResolver;
use Kinetis\VectorAi\Providers\ResolvedProvider;
use Kinetis\VectorAi\Settings\AiSettingsReader;
use Kinetis\VectorAi\Usage\UsageLedger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /vector/ai/filters/{resource}: a sentence as a filter of one
 * resource, `{text, timezone?, current?: {filter}}` in, `{filter, groups,
 * q, explanation, unresolved}` out — the list's own grammar, validated by
 * the list's own validator (FilterTranslator). Nothing is applied here:
 * the admin applies it through the ordinary list request, which
 * validates it again.
 *
 * Asked in this order, as this request's user and never another: the
 * resource (404), the user's viewAny on it, whether the resource may be
 * sent to a model at all (ModelBoundary), whether the feature is on,
 * configured and theirs to use (each 403), then the day's token budget
 * (429 until midnight UTC).
 */
#[RoutePrefix('/vector/ai')]
#[Middleware('@vector')]
final readonly class FiltersController
{
    /** The largest request body taken: a sentence and the list's current filter. */
    private const int MAX_BODY = 16384;

    public function __construct(
        private CurrentUserInterface $user,
        private AiAccessInterface $access,
        private AiConfig $config,
        private AiSettingsReader $settings,
        private ResourceService $resources,
        private ModelBoundary $boundary,
        private ProviderResolver $resolver,
        private UsageLedger $usage,
        private FilterTranslator $translator,
    ) {}

    #[Post('/filters/{resource}')]
    public function translate(string $resource, ServerRequestInterface $request): ResponseInterface
    {
        // the resource as this user sees it: their viewField policy has hidden what it hides
        $visible = $this->resources->resource($resource);
        $permissions = $this->resources->permissions($visible);

        if ($permissions['viewAny'] !== true) {
            throw new AiRequestException("This account may not list {$visible->label()}.", 403);
        }

        if (!$this->boundary->allows($visible)) {
            throw new AiRequestException("{$visible->label()} is kept from AI.", 403);
        }

        $provider = $this->provider();
        $budget = $this->settings->int('dailyTokenBudgetPerUser', 0);

        if ($this->usage->remaining((string) $this->user->id(), $budget) <= 0) {
            $midnight = new DateTimeImmutable('tomorrow', new DateTimeZone('UTC'));

            return ErrorResponse::create(429, "Today's AI budget is spent; it renews at midnight UTC.", [
                'Retry-After' => (string) max(1, $midnight->getTimestamp() - time()),
            ]);
        }

        [$text, $zone, $current] = $this->input($request);
        $translation = $this->translator->translate($visible, $permissions, $provider, $text, $zone, $current);

        return JsonResponse::create($translation->toArray(), 200, ['Cache-Control' => 'no-store']);
    }

    /** The feature's provider for this user, or 403 when it is off, unconfigured or not theirs. */
    private function provider(): ResolvedProvider
    {
        if (
            !$this->config->configured()
            || !$this->settings->bool(Feature::Filters->enabledSetting())
            || $this->settings->int('dailyTokenBudgetPerUser', 0) <= 0
            || !$this->access->canUse($this->user, Feature::Filters)
        ) {
            throw new AiRequestException('Plain-language filters are not available to this account.', 403);
        }

        try {
            return $this->resolver->for(Feature::Filters, $this->user);
        } catch (AiFailure $e) {
            throw new AiRequestException('Plain-language filters are not configured: ' . $e->getMessage(), 403);
        }
    }

    /**
     * The sentence, the time zone it is read in (UTC unless a valid one is
     * named) and the list's current filter, if sent.
     *
     * @return array{string, DateTimeZone, array<array-key, mixed>|null}
     */
    private function input(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();

        if (strlen($raw) > self::MAX_BODY) {
            throw new AiRequestException('The request body is larger than ' . self::MAX_BODY . ' bytes.', 413);
        }

        try {
            $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::fromViolations([new Violation([], 'invalid_json', 'The request body is not valid JSON.')]);
        }

        if (!is_array($body)) {
            throw ValidationException::fromViolations([new Violation([], 'invalid_json', 'The request body must be a JSON object.')]);
        }

        $text = is_string($body['text'] ?? null) ? trim($body['text']) : '';

        if ($text === '') {
            throw ValidationException::fromViolations([new Violation(['text'], 'required', 'is required.')]);
        }

        if (mb_strlen($text) > FilterTranslator::MAX_TEXT) {
            throw ValidationException::fromViolations([new Violation(['text'], 'too_long', 'must be at most ' . FilterTranslator::MAX_TEXT . ' characters.')]);
        }

        $current = $body['current']['filter'] ?? null;

        return [$text, self::zone($body['timezone'] ?? null), is_array($current) ? $current : null];
    }

    /**
     * The named zone when it is an IANA identifier, else UTC. The
     * constructor also takes an offset (`+02:00`, `+99:00` too) or an
     * abbreviation (`CET`); neither has a location, and neither is taken.
     */
    private static function zone(mixed $timezone): DateTimeZone
    {
        if (is_string($timezone)) {
            try {
                $zone = new DateTimeZone($timezone);

                if ($zone->getLocation() !== false) {
                    return $zone;
                }
            } catch (\DateInvalidTimeZoneException | \ValueError) {
                // not a zone (a NUL byte is a ValueError): UTC, below
            }
        }

        return new DateTimeZone('UTC');
    }
}
