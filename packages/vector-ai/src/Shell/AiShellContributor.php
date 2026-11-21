<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Shell;

use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Logging\SafeLogger;
use Kinetis\Vector\Attributes\ShellContributor;
use Kinetis\Vector\Auth\ActorContext;
use Kinetis\Vector\Auth\ActorScope;
use Kinetis\Vector\Http\ShellContributorInterface;
use Kinetis\Vector\Http\VisibleResources;
use Kinetis\VectorAi\Auth\AiAvailability;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Governance\ModelBoundary;
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
 * offered when it ships in this release and AiAvailability::availableNow()
 * lists it: what its own endpoint asks (offered(): the operator
 * configured a provider, the `ai` settings turn it on, the access policy
 * lets this user use it — for `assistantWrites`, the assistant's switch
 * and policy too), their daily token budget has tokens left, and the
 * resolver would find a provider, a model and a key for it, building no
 * provider and opening no key: this runs on every GET /vector/schema, a
 * 304 included, since the ETag covers it. Any failure computing it
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
        private LoggerInterface $logger,
        private VisibleResources $visible,
        private ModelBoundary $boundary,
        private CurrentUserInterface $user,
        private ActorScope $scopes,
    ) {}

    /**
     * The flags for $user. For this request's user — whom SchemaController
     * passes — from this request's services, reusing the resources and
     * permissions the descriptor already decided (VisibleResources). For
     * anyone else, from a request scope of their own, so that every answer
     * — the access policy, the budget, the resources they may list, the
     * fields they see — is theirs, not this request's user's.
     *
     * @return array{filters: bool, assistant: bool, assistantWrites: bool, writing: bool, excluded: object, excludedFields: object}
     */
    #[\Override]
    public function capabilities(CurrentUserInterface $user): array
    {
        if ($user === $this->user) {
            return $this->offering(self::shipped());
        }

        // their scope is built inside the catch-all too: whatever fails on the way turns AI off, never the schema
        try {
            return $this->scopes->run(new ActorContext($user), static function (RequestScope $scope): array {
                $theirs = $scope->get(self::class);

                if (!$theirs instanceof self) {
                    throw new \LogicException(self::class . ' did not resolve.');
                }

                return $theirs->offering(self::shipped());
            });
        } catch (\Throwable $e) {
            return $this->off($e);
        }
    }

    /**
     * The flags for this contributor's user — the request's — when $features are the ones that ship. Whether a
     * feature ships is asked first, so an unshipped one costs no settings,
     * ledger, resolver or key lookup. Any failure on the way — a missing
     * table, a transport that was not built, a policy that throws — turns
     * every feature off rather than failing GET /vector/schema.
     *
     * @param list<Feature> $features
     * @return array{filters: bool, assistant: bool, assistantWrites: bool, writing: bool, excluded: object, excludedFields: object}
     */
    public function offering(array $features): array
    {
        $flags = ['filters' => Feature::Filters, 'assistant' => Feature::Assistant, 'assistantWrites' => Feature::AssistantWrites, 'writing' => Feature::Writing];
        $offered = [];
        $excluded = [];
        $excludedFields = [];

        try {
            // only what ships is asked about: an unshipped feature costs no lookup
            $available = $this->availability->availableNow(array_values(array_filter($flags, static fn (Feature $f): bool => in_array($f, $features, true))));

            foreach ($flags as $key => $feature) {
                $offered[$key] = in_array($feature, $available, true);
            }

            if (in_array(true, $offered, true)) {
                [$excluded, $excludedFields] = $this->excluded();
            }
        } catch (\Throwable $e) {
            return $this->off($e);
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
     * Every feature off, after $e: logged as a warning naming its class
     * only — a message could carry a provider's detail — and a throwing
     * logger must not fail the schema either.
     *
     * @return array{filters: bool, assistant: bool, assistantWrites: bool, writing: bool, excluded: object, excludedFields: object}
     */
    private function off(\Throwable $e): array
    {
        SafeLogger::log($this->logger, LogLevel::WARNING, 'Computing the AI capabilities failed with ' . $e::class . '; every AI feature is off for this request.', ['exception_class' => $e::class]);

        return ['filters' => false, 'assistant' => false, 'assistantWrites' => false, 'writing' => false, 'excluded' => new \stdClass(), 'excludedFields' => new \stdClass()];
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

        // the resources the descriptor lists, as it decided them: no policy is asked a second time
        foreach ($this->visible->all() as $slug => ['descriptor' => $resource]) {
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
}
