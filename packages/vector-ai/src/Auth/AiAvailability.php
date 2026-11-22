<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Auth;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\VectorAi\Config\AiConfig;
use Kinetis\VectorAi\Failure\AiFailure;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Http\AiRequestException;
use Kinetis\VectorAi\Providers\ProviderResolver;
use Kinetis\VectorAi\Providers\ResolvedProvider;
use Kinetis\VectorAi\Settings\AiSettingsReader;
use Kinetis\VectorAi\Usage\UsageLedger;
use Psr\Http\Message\ResponseInterface;

/**
 * Whether this request's user may use an AI feature, the one answer every
 * AI endpoint and `capabilities.ai` (AiShellContributor) give:
 *
 * - permits(): the feature's switch in the `ai` settings is on and
 *   AiAccessInterface lets this user use it, and, for
 *   Feature::AssistantWrites, the same of Feature::Assistant: writes are
 *   the assistant's;
 * - offered(): permits(), and the operator configured a provider and the
 *   daily token budget is above 0, what a feature that calls a model
 *   asks before its provider;
 * - provider(): offered(), and the resolver's provider for it, or a 403;
 * - budgetSpent(): the 429 a spent daily budget is answered with; the
 *   one place every AI endpoint's 429 comes from;
 * - availableNow(): of some features, those offered() with tokens left
 *   today and a provider, model and key the resolver would find, what
 *   `capabilities.ai` publishes.
 *
 * Built per request: the user and the settings are this request's.
 */
final readonly class AiAvailability
{
    public function __construct(
        private CurrentUserInterface $user,
        private AiAccessInterface $access,
        private AiConfig $config,
        private AiSettingsReader $settings,
        private UsageLedger $usage,
        private ProviderResolver $resolver,
    ) {}

    /** Whether $feature is switched on and this user's to use; for writes, the assistant's too. */
    public function permits(Feature $feature): bool
    {
        if ($feature === Feature::AssistantWrites && !$this->permits(Feature::Assistant)) {
            return false;
        }

        return $this->settings->bool($feature->enabledSetting()) && $this->access->canUse($this->user, $feature);
    }

    /** permits(), with a provider configured and a daily budget above 0. */
    public function offered(Feature $feature): bool
    {
        return $this->config->configured()
            && $this->settings->int('dailyTokenBudgetPerUser', 0) > 0
            && $this->permits($feature);
    }

    /**
     * $feature's provider for this user.
     *
     * @param (\Closure(): bool)|null $shouldStop see ProviderResolver::for()
     * @throws AiRequestException 403 "… not available to this account." unless offered(); 403 "… not configured: <why>" when the resolver finds no provider, model or key
     */
    public function provider(Feature $feature, ?\Closure $shouldStop = null): ResolvedProvider
    {
        $subject = match ($feature) {
            Feature::Filters => 'Plain-language filters are',
            Feature::Assistant, Feature::AssistantWrites => 'The assistant is',
            Feature::Writing => 'Writing help is',
        };

        if (!$this->offered($feature)) {
            throw new AiRequestException("{$subject} not available to this account.", 403);
        }

        try {
            return $this->resolver->for($feature, $this->user, $shouldStop);
        } catch (AiFailure $e) {
            throw new AiRequestException("{$subject} not configured: " . $e->getMessage(), 403);
        }
    }

    /**
     * Of $features, those this user may use right now: offered(), tokens
     * left of today's budget (read once, and only when one is offered)
     * and a provider, model and key ProviderResolver::resolvable() would
     * find, decided without building a provider or opening a key, so
     * GET /vector/schema can ask it on every request.
     *
     * @param list<Feature> $features
     * @return list<Feature> in $features' order
     */
    public function availableNow(array $features): array
    {
        $remaining = null;
        $available = [];

        foreach ($features as $feature) {
            if (!$this->offered($feature)) {
                continue;
            }

            $remaining ??= $this->remaining();

            if ($remaining > 0 && $this->resolver->resolvable($feature)) {
                $available[] = $feature;
            }
        }

        return $available;
    }

    /** The tokens of today's budget this user has left; 0 when the budget is 0. */
    public function remaining(): int
    {
        return $this->usage->remaining((string) $this->user->id(), $this->settings->int('dailyTokenBudgetPerUser', 0));
    }

    /** 429 with Retry-After counting the seconds to midnight UTC when this user's budget is spent; otherwise null. */
    public function budgetSpent(): ?ResponseInterface
    {
        return $this->remaining() > 0 ? null : self::budgetResponse();
    }

    /** The answer to a request made with today's budget spent: 429 until midnight UTC. */
    private static function budgetResponse(): ResponseInterface
    {
        $midnight = new DateTimeImmutable('tomorrow', new DateTimeZone('UTC'));

        return ErrorResponse::create(429, "Today's AI budget is spent; it renews at midnight UTC.", [
            'Retry-After' => (string) max(1, $midnight->getTimestamp() - time()),
        ]);
    }
}
