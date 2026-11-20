<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Shell;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\Logging\SafeLogger;
use Kinetis\Vector\Attributes\ShellContributor;
use Kinetis\Vector\Http\ShellContributorInterface;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\VectorAi\Auth\AiAvailability;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Governance\ModelBoundary;
use Kinetis\VectorAi\Providers\ProviderResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * `capabilities.ai` in GET /vector/schema: per user, which AI features
 * the admin offers — `filters`, `assistant`, `assistantWrites`,
 * `writing` — and what is kept from the model, once a feature that sends
 * to a model is offered (empty objects until then): `excluded`, each
 * resource this user may list that ModelBoundary keeps back whole, as
 * `{slug: true}`; and `excludedFields`, for each other resource they may
 * list, the fields they see that ModelBoundary keeps back, as
 * `{slug: [field, …]}` — a resource with none is absent. A feature is
 * offered when it ships in this release and AiAvailability::offered() —
 * what its own endpoint asks: the operator configured a provider, the
 * `ai` settings turn it on, the access policy lets this user use it (for
 * `assistantWrites`, the assistant's switch and policy too) — their daily
 * token budget has tokens left, and the resolver would find a provider, a
 * model and a key for it (ProviderResolver::resolvable(), which builds no
 * provider and opens no key: this runs on every GET /vector/schema, a
 * 304 included, since the ETag covers it). Any failure computing it
 * leaves every feature off and logs a warning naming the exception's
 * class — never its message, which could carry a provider's detail. The key is absent altogether where
 * kinetis/vector-ai is not installed.
 */
#[ShellContributor(name: 'ai')]
final readonly class AiShellContributor implements ShellContributorInterface
{
    /**
     * The features this release implements; a flag stays false until its
     * feature is listed here.
     *
     * @return list<Feature>
     */
    public static function shipped(): array
    {
        return [Feature::Filters, Feature::Assistant, Feature::AssistantWrites, Feature::Writing];
    }

    public function __construct(
        private AiAvailability $availability,
        private ProviderResolver $resolver,
        private LoggerInterface $logger,
        private ResourceService $resources,
        private ModelBoundary $boundary,
    ) {}

    /** @return array{filters: bool, assistant: bool, assistantWrites: bool, writing: bool, excluded: object, excludedFields: object} */
    #[\Override]
    public function capabilities(CurrentUserInterface $user): array
    {
        return $this->offering(self::shipped(), $user);
    }

    /**
     * The flags for $user when $features are the ones that ship. Whether a
     * feature ships is asked first, so an unshipped one costs no settings,
     * ledger, resolver or key lookup. Any failure on the way — a missing
     * table, a transport that was not built, a policy that throws — turns
     * every feature off rather than failing GET /vector/schema.
     *
     * @param list<Feature> $features
     * @return array{filters: bool, assistant: bool, assistantWrites: bool, writing: bool, excluded: object, excludedFields: object}
     */
    public function offering(array $features, CurrentUserInterface $user): array
    {
        $flags = ['filters' => Feature::Filters, 'assistant' => Feature::Assistant, 'assistantWrites' => Feature::AssistantWrites, 'writing' => Feature::Writing];
        $remaining = null;
        $offered = [];
        $excluded = [];
        $excludedFields = [];

        try {
            $availability = $this->availability->forUser($user);

            foreach ($flags as $key => $feature) {
                $offered[$key] = in_array($feature, $features, true) && $this->offered($feature, $availability, $remaining);
            }

            if (in_array(true, $offered, true)) {
                [$excluded, $excludedFields] = $this->excluded();
            }
        } catch (\Throwable $e) {
            $offered = array_map(static fn (): bool => false, $flags);
            $excluded = [];
            $excludedFields = [];
            // the class only: a message could carry a provider's detail; a throwing logger must not fail the schema either
            SafeLogger::log($this->logger, LogLevel::WARNING, 'Computing the AI capabilities failed with ' . $e::class . '; every AI feature is off for this request.', ['exception_class' => $e::class]);
        }

        return [
            'filters' => $offered['filters'],
            'assistant' => $offered['assistant'],
            'assistantWrites' => $offered['assistantWrites'],
            'writing' => $offered['writing'],
            'excluded' => $excluded === [] ? new \stdClass() : (object) $excluded,
            'excludedFields' => $excludedFields === [] ? new \stdClass() : (object) $excludedFields,
        ];
    }

    /**
     * The resources this user may list that are kept from the model whole,
     * so the admin offers no AI on them; and, of the others they may list,
     * the fields they see that are kept back, so the admin offers no AI on
     * those fields — writing help, for one.
     *
     * @return array{array<string, true>, array<string, list<string>>}
     */
    private function excluded(): array
    {
        $excluded = [];
        $fields = [];

        foreach ($this->resources->registry()->resources() as $slug => $resource) {
            if ($this->resources->permissions($resource)['viewAny'] !== true) {
                continue;
            }

            if (!$this->boundary->allows($resource)) {
                $excluded[$slug] = true;

                continue;
            }

            $kept = $this->boundary->keptFields($resource);

            if ($kept !== []) {
                $fields[$slug] = $kept;
            }
        }

        return [$excluded, $fields];
    }

    /**
     * Whether a shipped $feature is on: AiAvailability::offered() — the
     * predicate its endpoint asks — tokens left today, and a provider,
     * model and key the resolver would find for it, decided from the
     * configuration without building a provider or opening a key
     * (ProviderResolver::resolvable()). $remaining caches the budget's
     * answer across the features of one call.
     *
     * @throws \Throwable from any dependency; offering() turns it into "off"
     */
    private function offered(Feature $feature, AiAvailability $availability, ?int &$remaining): bool
    {
        if (!$availability->offered($feature)) {
            return false;
        }

        $remaining ??= $availability->remaining();

        return $remaining > 0 && $this->resolver->resolvable($feature);
    }
}
