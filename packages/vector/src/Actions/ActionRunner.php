<?php

declare(strict_types=1);

namespace Kinetis\Vector\Actions;

use Kinetis\Authorization\AuthorizationResponse;
use Kinetis\Authorization\Gate;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Auth\ActorContext;
use Kinetis\Vector\Exception\ForbiddenOperationException;
use Kinetis\Vector\Exception\ResourceNotFoundException;
use Kinetis\Vector\Jobs\RunActionJob;
use Kinetis\Vector\Jobs\VectorQueue;
use Kinetis\Vector\Store\JobStore;
use Kinetis\Vector\Query\ListRequest;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Write\FieldInput;
use Kinetis\Vector\Write\RecordWriter;
use Kinetis\Vector\Write\ValueConverter;

/**
 * Runs one action synchronously: resolves the selection (ids, or every
 * record a filter matches, within the resource's bulk limit), checks
 * the policy and the action's own visibility for every record,
 * validates the dialog's values, hands the loaded entities to the
 * action, flushes once and logs the run.
 */
final readonly class ActionRunner
{
    public function __construct(
        private ResourceService $reader,
        private RecordWriter $writer,
        private RequestScope $scope,
        private Gate $gate,
        private CurrentUserInterface $user,
        private EntityManagerRegistry $managers,
        private FieldInput $input,
        private ValueConverter $converter,
        private JobStore $jobs,
        private VectorQueue $queue,
        private ActorContext $actor,
    ) {}

    /**
     * Runs an action from a request body: synchronously, or, for a
     * queued action, as a background job the response names.
     *
     * @param array<array-key, mixed> $body `{ids: [...]} | {all: {filter, q}}`, plus `fields: {...}`
     */
    public function run(ResourceDescriptor $resource, string $name, array $body): ActionResponse
    {
        $compiled = $this->admitted($resource, $name);
        $rawFields = $body['fields'] ?? [];

        if (!is_array($rawFields)) {
            throw ValidationException::fromViolations([new Violation(['fields'], 'type_mismatch', 'must be an object.')]);
        }

        if ($compiled['queued']) {
            $fields = $this->fieldsOf($compiled);
            $this->input->validate($fields, $rawFields); // refuse a bad dialog now, not in the worker

            $reason = $this->queue->unavailableReason();

            if ($reason !== null) {
                throw new ForbiddenOperationException($reason);
            }

            $selection = $compiled['standalone'] ? [] : $this->selectionBody($resource, $body);
            $jobId = $this->jobs->create('action', $resource->slug(), $name, (string) $this->user->id(), impersonatorId: $this->actor->impersonatorId);
            /** @var array<string, mixed> $rawFields */
            $this->queue->enqueue($this->jobs, $jobId, new RunActionJob($jobId, $resource->slug(), $name, $selection, $rawFields, (string) $this->user->id()));

            return ActionResponse::queued($jobId, "{$compiled['label']} was queued.");
        }

        $ids = $compiled['standalone'] ? [] : $this->selection($resource, $body);

        return $this->runNow($resource, $name, $ids, $rawFields);
    }

    /**
     * Runs an action on exactly these identifiers, in this request: the
     * synchronous path, and one chunk of a queued job.
     *
     * @param list<int|string> $ids
     * @param array<array-key, mixed> $rawFields
     */
    public function runNow(ResourceDescriptor $resource, string $name, array $ids, array $rawFields): ActionResponse
    {
        $compiled = $this->admitted($resource, $name);
        $instance = $this->scope->get($compiled['class']);

        if (!$instance instanceof VectorAction) {
            throw new \LogicException("{$compiled['class']} is not a VectorAction.");
        }

        $values = $this->input->validate($this->fieldsOf($compiled), $rawFields);
        $records = [];
        $entities = [];

        foreach ($ids as $id) {
            $record = $this->reader->show($resource, (string) $id);

            if (!$instance->canRun($this->user, $record)) {
                throw new ForbiddenOperationException("The action \"{$name}\" cannot run on record {$id}.");
            }

            $records[] = $record;
        }

        if ($ids !== []) {
            $entities = array_values($this->writer->loadMany($resource, array_map(strval(...), $ids)));
        }

        $response = $instance->handle(new ActionRequest($resource, $this->user, $this->managers->managerFor($resource->entityClass()), $entities, $records, $values));

        if ($response->type === 'danger') {
            return $response; // the action declined; nothing is flushed
        }

        $this->writer->commitAction($resource, $name, $ids);

        return $response->affected === 0 && $ids !== [] ? $response->withAffected(count($ids)) : $response;
    }

    /**
     * The identifiers a stored selection names, for a job: ids as given,
     * or every record the filter matches within the bulk limit.
     *
     * @param array<string, mixed> $selection
     * @return list<int|string>
     */
    public function resolveSelection(ResourceDescriptor $resource, array $selection, bool $standalone): array
    {
        return $standalone ? [] : $this->selection($resource, $selection);
    }

    /**
     * The action, checked for existence, the policy and the actor's own
     * visibility; the instance is not built here.
     *
     * @return array<string, mixed>
     */
    private function admitted(ResourceDescriptor $resource, string $name): array
    {
        $compiled = $resource->action($name) ?? throw ResourceNotFoundException::slug("{$resource->slug()}/actions/{$name}");
        $policy = $this->reader->policy($resource);
        $this->gate->authorize($this->user, $policy->viewAny(...));
        $this->gate->authorize($this->user, static fn (CurrentUserInterface $u): bool|AuthorizationResponse => $policy->runAction($u, $name));

        $instance = $this->scope->get($compiled['class']);

        if (!$instance instanceof VectorAction) {
            throw new \LogicException("{$compiled['class']} is not a VectorAction.");
        }

        if (!$instance->canRun($this->user, null)) {
            throw new ForbiddenOperationException("The action \"{$name}\" is not available to this account.");
        }

        return $compiled;
    }

    /**
     * @param array<string, mixed> $compiled
     * @return array<string, array<string, mixed>>
     */
    private function fieldsOf(array $compiled): array
    {
        $fields = [];

        foreach ($compiled['fields'] as $field) {
            $fields[$field['name']] = $field;
        }

        return $fields;
    }

    /**
     * The selection as the job stores it: validated in shape now, resolved to ids in the worker.
     *
     * @param array<array-key, mixed> $body
     * @return array<string, mixed>
     */
    private function selectionBody(ResourceDescriptor $resource, array $body): array
    {
        $all = $body['all'] ?? null;

        if (is_array($all)) {
            ListRequest::fromSelection($all, $resource, $this->converter); // must parse today

            return ['all' => $all];
        }

        return ['ids' => $this->selection($resource, $body)];
    }

    /**
     * @param array<array-key, mixed> $body
     * @return list<int|string>
     */
    private function selection(ResourceDescriptor $resource, array $body): array
    {
        $ids = $body['ids'] ?? null;
        $all = $body['all'] ?? null;

        if (is_array($all)) {
            return $this->reader->matchingIds($resource, ListRequest::fromSelection($all, $resource, $this->converter));
        }

        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > $resource->bulkLimit() || array_any($ids, static fn (mixed $id): bool => !is_int($id) && !is_string($id))) {
            throw ValidationException::fromViolations([new Violation(['ids'], 'type_mismatch', "must be a list of 1 to {$resource->bulkLimit()} identifiers, or pass all: {filter, q}.")]);
        }

        /** @var list<int|string> $ids */
        return array_values(array_unique($ids));
    }
}
