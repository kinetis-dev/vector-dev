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
use Kinetis\VectorAi\Writing\WritingGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * `capabilities.ai` in GET /vector/schema, per user (docs/ai.md, "What the
 * admin is told"): a flag for each feature shipped() lists, true when
 * AiAvailability::availableNow() lists it, and, once one is offered, what
 * is kept from the model, `excluded` and `excludedFields`
 * (ModelBoundary), and `noWriting` (WritingGuard::offers()). It runs on
 * every GET /vector/schema, a 304 included, so it builds no provider and
 * opens no key. Any failure computing it leaves every feature off and logs
 * a warning naming the exception's class, never its message, which could
 * carry a provider's detail.
 */
#[ShellContributor(name: 'ai')]
final readonly class AiShellContributor implements ShellContributorInterface
{
    /**
     * The features whose flags may be true; a feature not listed here is
     * never offered.
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
        private WritingGuard $writing,
    ) {}

    /**
     * The flags for $user. For this request's user (whom SchemaController
     * passes) from this request's services, reusing the resources and
     * permissions the descriptor already decided (VisibleResources). For
     * anyone else, from a request scope of their own, so that every answer
     * (the access policy, the budget, the resources they may list, the
     * fields they see) is theirs, not this request's user's.
     *
     * @return array{filters: bool, assistant: bool, assistantWrites: bool, writing: bool, excluded: object, excludedFields: object, noWriting: object}
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
     * The flags for this contributor's user (the request's) offering at
     * most $features (shipped()). A feature outside them costs no settings,
     * ledger, resolver or key lookup. Any failure on the way (a missing
     * table, a transport that was not built, a policy that throws) turns
     * every feature off rather than failing GET /vector/schema.
     *
     * @param list<Feature> $features
     * @return array{filters: bool, assistant: bool, assistantWrites: bool, writing: bool, excluded: object, excludedFields: object, noWriting: object}
     */
    public function offering(array $features): array
    {
        $flags = ['filters' => Feature::Filters, 'assistant' => Feature::Assistant, 'assistantWrites' => Feature::AssistantWrites, 'writing' => Feature::Writing];
        $offered = [];
        $excluded = [];
        $excludedFields = [];
        $noWriting = [];

        try {
            // only what ships is asked about: an unshipped feature costs no lookup
            $available = $this->availability->availableNow(array_values(array_filter($flags, static fn (Feature $f): bool => in_array($f, $features, true))));

            foreach ($flags as $key => $feature) {
                $offered[$key] = in_array($feature, $available, true);
            }

            if (in_array(true, $offered, true)) {
                [$excluded, $excludedFields, $noWriting] = $this->excluded();
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
            'noWriting' => $noWriting === [] ? new \stdClass() : (object) $noWriting,
        ];
    }

    /**
     * Every feature off, after $e: logged as a warning naming its class
     * only (a message could carry a provider's detail) and a throwing
     * logger must not fail the schema either.
     *
     * @return array{filters: bool, assistant: bool, assistantWrites: bool, writing: bool, excluded: object, excludedFields: object, noWriting: object}
     */
    private function off(\Throwable $e): array
    {
        SafeLogger::log($this->logger, LogLevel::WARNING, 'Computing the AI capabilities failed with ' . $e::class . '; every AI feature is off for this request.', ['exception_class' => $e::class]);

        return ['filters' => false, 'assistant' => false, 'assistantWrites' => false, 'writing' => false, 'excluded' => new \stdClass(), 'excludedFields' => new \stdClass(), 'noWriting' => new \stdClass()];
    }

    /**
     * The resources this user may list that are kept from the model whole,
     * so the admin offers no AI on them; and, of the others they may list,
     * the fields they see that are kept back, so the admin offers no AI on
     * those fields, writing help, for one; and the text fields of those
     * others that writing help is not offered on (WritingGuard::offers()).
     *
     * @return array{array<string, true>, array<string, list<string>>, array<string, list<string>>}
     */
    private function excluded(): array
    {
        $excluded = [];
        $fields = [];
        $noWriting = [];

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

            $off = [];

            foreach ($resource->fields() as $name => $field) {
                if (in_array($field['type'] ?? null, WritingGuard::TYPES, true) && !$this->writing->offers($resource, $field)) {
                    $off[] = (string) $name;
                }
            }

            if ($off !== []) {
                $noWriting[$slug] = $off;
            }
        }

        return [$excluded, $fields, $noWriting];
    }
}
