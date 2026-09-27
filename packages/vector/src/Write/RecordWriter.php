<?php

declare(strict_types=1);

namespace Kinetis\Vector\Write;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Authorization\AuthorizationResponse;
use Kinetis\Authorization\Gate;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\Orm\Exception\OptimisticLockException;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Exception\ConflictException;
use Kinetis\Vector\Exception\ForbiddenOperationException;
use Kinetis\Vector\Exception\RecordNotFoundException;
use Kinetis\Vector\Auth\ActorContext;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Store\AuditStore;
use Kinetis\Vector\VectorResource;

/**
 * The write side: validate, authorize, build or load the entity, apply
 * the resource's hooks, flush once, log the event, and answer with the
 * record as the read side serializes it. Request-scoped through the
 * managers it opens, one per connection an entity lives on.
 */
final readonly class RecordWriter
{
    public function __construct(
        private EntityManagerRegistry $managers,
        private RequestScope $scope,
        private Gate $gate,
        private CurrentUserInterface $user,
        private RecordInput $input,
        private EntityWriter $writer,
        private ResourceService $reader,
        private AuditStore $audit,
        private VectorDatabase $vector,
        private ValueConverter $converter,
        private ActorContext $actor,
    ) {}

    /**
     * @param array<array-key, mixed> $payload
     * @return array<string, mixed> the created record
     */
    public function create(ResourceDescriptor $resource, array $payload): array
    {
        $this->gate->authorize($this->user, $this->policy($resource)->create(...));
        $creation = $resource->creation();

        if ($creation['possible'] !== true) {
            throw new ForbiddenOperationException("This resource cannot create records: {$creation['reason']}");
        }

        [$fields, $rowsOf] = self::takeRepeaters($resource, $payload, 'create');
        [$values, $violations] = $this->validateOwn($resource, $fields, false, []);
        [$plans, $rowViolations] = $this->planRepeaters($resource, $rowsOf, null);

        foreach ($resource->fields() as $name => $field) {
            if (($field['repeater']['min'] ?? 0) > 0 && !array_key_exists($name, $rowsOf) && in_array('create', $field['views'], true)) {
                $rowViolations[] = new Violation([$name], 'required', "must hold at least {$field['repeater']['min']} rows.");
            }
        }

        if ($violations !== [] || $rowViolations !== []) {
            throw ValidationException::fromViolations([...$violations, ...$rowViolations]);
        }

        $this->authorizeRepeaters($plans);
        $context = new WriteContext($resource, $this->user, $this->manager($resource), $payload);
        $instance = $this->resource($resource);
        $entity = $instance->create($values, $context) ?? $this->writer->instantiate($resource, $values);

        foreach (array_filter($resource->timestamps()) as $property) {
            $this->writer->stamp($resource, $entity, $property);
        }

        // the rows first, so the parent's own hook sees the aggregate it is about to write
        $plans = $this->applyRepeaters($resource, $plans, $entity);
        $instance->beforeCreate($entity, $context);
        $this->manager($resource)->persist($entity);
        $this->flush($resource);
        $instance->afterCreate($entity, $context);
        $this->finishRepeaters($plans);

        $id = $this->writer->read($entity, $resource->primaryKeyField());
        $this->log($resource, 'create', [$id]);
        $record = $this->reader->written($resource, (string) $id);
        $this->audit->record($resource->slug(), [(string) $id], 'create', self::auditable($record), (string) $this->user->id(), $this->impersonator());

        return $record;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<string, mixed> the updated record
     */
    public function update(ResourceDescriptor $resource, string $id, array $payload, ?string $expectedVersion): array
    {
        return $this->write($resource, $id, $payload, $expectedVersion, 'update');
    }

    /**
     * Puts back what one audit entry changed: each field the entry
     * changed that this user may see and edit gets its `from` value,
     * through update()'s own authorization, validation and hooks, audited
     * as a `revert`. A field changed again since then fails the whole
     * revert with a conflict rather than overwriting the later change.
     *
     * @return array<string, mixed> the reverted record
     */
    public function revert(ResourceDescriptor $resource, string $id, int $entryId): array
    {
        $current = $this->reader->show($resource, $id);
        $entry = $this->audit->find($resource->slug(), $id, $entryId)
            ?? throw new RecordNotFoundException("No history entry {$entryId} for this {$resource->labelSingular()}.");

        if (!in_array($entry['event'], ['update', 'revert'], true)) {
            throw ValidationException::fromViolations([new Violation(['entry'], 'not_revertable', "is a {$entry['event']}; only an update can be reverted.")]);
        }

        $payload = [];
        $changedSince = [];

        foreach ($entry['changes'] as $name => $change) {
            $field = $resource->field($name);

            if ($field === null || !is_array($change) || $field['readonly'] === true || !in_array('update', $field['views'], true)
                || in_array($field['type'], ['id', 'hasOne', 'hasMany', 'manyToMany', 'password'], true)) {
                continue;
            }

            if (self::comparable($current[$name] ?? null) !== self::comparable($change['to'] ?? null)) {
                $changedSince[] = $field['label'];

                continue;
            }

            $from = $change['from'] ?? null;
            $payload[$name] = $field['type'] === 'belongsTo' && is_array($from) ? ($from['id'] ?? null) : $from;
        }

        if ($changedSince !== []) {
            throw ConflictException::changedSince($resource->slug(), $changedSince);
        }

        if ($payload === []) {
            throw ValidationException::fromViolations([new Violation(['entry'], 'not_revertable', 'changed no field you can edit.')]);
        }

        // at the version it was compared at: a write racing the revert is a conflict too
        return $this->write($resource, $id, $payload, isset($current['_version']) && is_int($current['_version']) ? (string) $current['_version'] : null, 'revert');
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<string, mixed> the updated record
     */
    private function write(ResourceDescriptor $resource, string $id, array $payload, ?string $expectedVersion, string $event): array
    {
        $current = $this->reader->show($resource, $id);
        $policy = $this->policy($resource);
        $this->gate->authorize($this->user, static fn (CurrentUserInterface $u): bool|AuthorizationResponse => $policy->update($u, $current));

        [$fields, $rowsOf] = self::takeRepeaters($resource, $payload, 'update');
        [$values, $violations] = $this->validateOwn($resource, $fields, true, $current);

        if ($violations !== [] && $rowsOf === []) {
            throw ValidationException::fromViolations($violations);
        }

        $with = array_keys(array_filter($values, static fn (mixed $v, string $name) => ($resource->field($name)['type'] ?? null) === 'manyToMany', ARRAY_FILTER_USE_BOTH));
        $entity = $this->load($resource, $id, [...$with, ...array_keys($rowsOf)]);
        [$plans, $rowViolations] = $this->planRepeaters($resource, $rowsOf, $entity);

        if ($violations !== [] || $rowViolations !== []) {
            throw ValidationException::fromViolations([...$violations, ...$rowViolations]);
        }

        $version = $expectedVersion ?? (isset($payload['version']) && is_scalar($payload['version']) ? (string) $payload['version'] : null);

        if ($resource->versionField() !== null && $version !== null && (string) $this->writer->read($entity, $resource->versionField()) !== $version) {
            throw ConflictException::stale($resource->slug());
        }

        $this->authorizeMembership($resource, $entity, $current, $values);
        $this->authorizeRepeaters($plans);
        $context = new WriteContext($resource, $this->user, $this->manager($resource), $payload);
        $instance = $this->resource($resource);

        if (!$instance->apply($entity, $values, $context)) {
            $this->writer->assign($resource, $entity, $values);
        }

        $plans = $this->applyRepeaters($resource, $plans, $entity);

        if (($updated = $resource->timestamps()['updated']) !== null) {
            $this->writer->stamp($resource, $entity, $updated);
        }

        $instance->beforeUpdate($entity, $context);
        $this->flush($resource);
        $instance->afterUpdate($entity, $context);
        $this->finishRepeaters($plans);
        $this->log($resource, 'update', [$id]);
        $record = $this->reader->written($resource, $id);
        $changes = self::diff(self::auditable($current), self::auditable($record));

        if ($changes !== []) {
            $this->audit->record($resource->slug(), [$id], $event, $changes, (string) $this->user->id(), $this->impersonator());
        }

        return $record;
    }

    /**
     * The record's own fields validated, the violations collected rather
     * than thrown, so a repeater's rows can be reported with them.
     *
     * @param array<array-key, mixed> $payload
     * @param array<string, mixed> $current
     * @return array{0: array<string, mixed>, 1: list<Violation>}
     */
    private function validateOwn(ResourceDescriptor $resource, array $payload, bool $partial, array $current): array
    {
        try {
            return [$this->input->validate($resource, $payload, $partial, $current), []];
        } catch (ValidationException $e) {
            return [[], $e->violations];
        }
    }

    /**
     * The repeater rows a payload sends, taken out of it: the record's own
     * input validates without them.
     *
     * @param array<array-key, mixed> $payload
     * @return array{0: array<array-key, mixed>, 1: array<string, mixed>} the payload without them, and field => the rows sent
     */
    private static function takeRepeaters(ResourceDescriptor $resource, array $payload, string $view): array
    {
        $rows = [];

        foreach ($resource->fields() as $name => $field) {
            if (($field['repeater'] ?? null) !== null && in_array($view, $field['views'], true) && array_key_exists($name, $payload)) {
                $rows[$name] = $payload[$name];
                unset($payload[$name]);
            }
        }

        return [$payload, $rows];
    }

    /**
     * Validates each repeater's rows as the child resource validates a
     * record — a row with an `id` as an update of that child of this
     * record, one without as a create — and pairs every row with what it
     * writes. `$owner` is the loaded parent (null when creating): the rows
     * its collection holds now, of which the ones not sent are removed.
     *
     * @param array<string, mixed> $rowsOf field => the rows sent
     * @return array{0: list<array<string, mixed>>, 1: list<Violation>} a plan per field, and every violation under its field and row
     */
    private function planRepeaters(ResourceDescriptor $resource, array $rowsOf, ?object $owner): array
    {
        $plans = [];
        $violations = [];

        foreach ($rowsOf as $name => $rows) {
            $field = $resource->fields()[$name];
            $repeater = $field['repeater'];

            if (!is_array($rows) || !array_is_list($rows)) {
                $violations[] = new Violation([$name], 'type_mismatch', 'must be a list of rows.');

                continue;
            }

            if (count($rows) < $repeater['min'] || count($rows) > $repeater['max']) {
                $violations[] = new Violation([$name], 'count_out_of_range', "must hold {$repeater['min']} to {$repeater['max']} rows.", ['min' => $repeater['min'], 'max' => $repeater['max']]);

                continue;
            }

            $child = $this->reader->resource($field['relation']['resource']);
            // a row writes the repeater's fields and nothing else — the parent it belongs to least of all
            $form = $child->withoutFields(array_values(array_diff(array_keys($child->fields()), $repeater['fields'])));
            $existing = [];

            foreach (($owner === null ? null : $this->writer->read($owner, $name)) ?? [] as $entity) {
                $existing[(string) $this->writer->read($entity, $child->primaryKeyField())] = $entity;
            }

            $currentOf = $this->reader->records($child, array_map(strval(...), array_keys($existing)));
            $planned = [];
            $kept = [];

            foreach ($rows as $index => $row) {
                if (!is_array($row) || ($row !== [] && array_is_list($row))) {
                    $violations[] = new Violation([$name, $index], 'type_mismatch', 'must be an object of field values.');

                    continue;
                }

                $id = is_int($row['id'] ?? null) || (is_string($row['id'] ?? null) && $row['id'] !== '') ? (string) $row['id'] : null;
                unset($row['id']);

                if ($id !== null && (!isset($existing[$id]) || isset($kept[$id]))) {
                    $violations[] = new Violation([$name, $index, 'id'], 'unknown_row', 'is not a row of this record.');

                    continue;
                }

                $current = $id === null ? [] : $currentOf[$id] ?? [];

                try {
                    $values = $this->input->validate($form, $row, $id !== null, $current);
                } catch (ValidationException $e) {
                    foreach ($e->violations as $violation) {
                        $violations[] = $violation->under($name, $index);
                    }

                    continue;
                }

                if ($id !== null) {
                    $kept[$id] = true;
                }

                $planned[] = ['id' => $id, 'entity' => $id === null ? null : $existing[$id], 'values' => $values, 'current' => $current];
            }

            $removed = [];

            foreach ($existing as $id => $entity) {
                if (!isset($kept[(string) $id])) {
                    $removed[] = ['id' => (string) $id, 'entity' => $entity, 'current' => $currentOf[(string) $id] ?? []];
                }
            }

            $plans[] = ['field' => $name, 'child' => $child, 'inverse' => $field['relation']['inverse'], 'rows' => $planned, 'removed' => $removed];
        }

        return [$plans, $violations];
    }

    /**
     * Each row is the child resource's own create, update or delete, and
     * its policy decides it.
     *
     * @param list<array<string, mixed>> $plans
     */
    private function authorizeRepeaters(array $plans): void
    {
        foreach ($plans as $plan) {
            $policy = $this->policy($plan['child']);

            foreach ($plan['rows'] as $row) {
                $current = $row['current'];

                if ($row['entity'] === null) {
                    $this->gate->authorize($this->user, $policy->create(...));
                } else {
                    $this->gate->authorize($this->user, static fn (CurrentUserInterface $u): bool|AuthorizationResponse => $policy->update($u, $current));
                }
            }

            foreach ($plan['removed'] as $row) {
                $current = $row['current'];
                $this->gate->authorize($this->user, static fn (CurrentUserInterface $u): bool|AuthorizationResponse => $policy->delete($u, $current));
            }
        }
    }

    /**
     * Writes the planned rows into the owner's collections: children
     * changed in place, new ones built pointing at the owner, and the rest
     * left out, which the flush deletes. Each child's resource hooks run
     * as they would for a record of its own.
     *
     * @param list<array<string, mixed>> $plans
     * @return list<array<string, mixed>> the plans, every row holding its entity and context
     */
    private function applyRepeaters(ResourceDescriptor $resource, array $plans, object $owner): array
    {
        foreach ($plans as $p => $plan) {
            $child = $plan['child'];
            $instance = $this->resource($child);
            $collection = [];

            foreach ($plan['rows'] as $r => $row) {
                $context = new WriteContext($child, $this->user, $this->manager($child), $row['values']);
                $entity = $row['entity'];

                if ($entity === null) {
                    $values = [...$row['values'], $plan['inverse'] => $owner];
                    $entity = $instance->create($values, $context) ?? $this->writer->instantiate($child, $values);

                    foreach (array_filter($child->timestamps()) as $property) {
                        $this->writer->stamp($child, $entity, $property);
                    }

                    $instance->beforeCreate($entity, $context);
                } else {
                    if (!$instance->apply($entity, $row['values'], $context)) {
                        $this->writer->assign($child, $entity, $row['values']);
                    }

                    if (($updated = $child->timestamps()['updated']) !== null) {
                        $this->writer->stamp($child, $entity, $updated);
                    }

                    $instance->beforeUpdate($entity, $context);
                }

                $plans[$p]['rows'][$r]['entity'] = $entity;
                $plans[$p]['rows'][$r]['context'] = $context;
                $collection[] = $entity;
            }

            foreach ($plan['removed'] as $row) {
                $instance->beforeDelete($row['entity'], new WriteContext($child, $this->user, $this->manager($child), []));
            }

            $this->writer->assign($resource, $owner, [$plan['field'] => $collection]);
        }

        return $plans;
    }

    /**
     * After the flush: each child's after-hooks, and its own log and
     * audit entries, as if it had been written on its own.
     *
     * @param list<array<string, mixed>> $plans
     */
    private function finishRepeaters(array $plans): void
    {
        foreach ($plans as $plan) {
            $child = $plan['child'];
            $instance = $this->resource($child);
            $created = [];
            $updated = [];

            foreach ($plan['rows'] as $row) {
                $id = (string) $this->writer->read($row['entity'], $child->primaryKeyField());

                if ($row['id'] === null) {
                    $instance->afterCreate($row['entity'], $row['context']);
                    $created[] = $id;
                } else {
                    $instance->afterUpdate($row['entity'], $row['context']);
                    $updated[$id] = $row['current'];
                }
            }

            foreach ($plan['removed'] as $row) {
                $instance->afterDelete($row['entity'], new WriteContext($child, $this->user, $this->manager($child), []));
            }

            $after = $this->reader->records($child, [...$created, ...array_map(strval(...), array_keys($updated))], scoped: false);
            $actor = (string) $this->user->id();

            foreach ($created as $id) {
                $this->audit->record($child->slug(), [$id], 'create', self::auditable($after[$id] ?? []), $actor, $this->impersonator());
            }

            foreach ($updated as $id => $before) {
                $changes = self::diff(self::auditable($before), self::auditable($after[(string) $id] ?? $before));

                if ($changes !== []) {
                    $this->audit->record($child->slug(), [(string) $id], 'update', $changes, $actor, $this->impersonator());
                }
            }

            $removed = array_column($plan['removed'], 'id');

            if ($removed !== []) {
                $this->audit->record($child->slug(), $removed, 'delete', [], $actor, $this->impersonator());
            }

            foreach (['create' => $created, 'update' => array_keys($updated), 'delete' => $removed] as $action => $ids) {
                if ($ids !== []) {
                    $this->log($child, $action, $ids);
                }
            }
        }
    }

    /**
     * Deletes one record: a soft-deleting resource has its marker
     * stamped, any other its row removed. `$force` removes the row of a
     * soft-deleting resource for good.
     */
    public function delete(ResourceDescriptor $resource, string $id, bool $force = false): void
    {
        $this->batch($resource, [$id], $force ? 'forceDelete' : 'delete');
    }

    /**
     * @param list<string> $ids
     * @return int how many were deleted
     */
    public function deleteMany(ResourceDescriptor $resource, array $ids): int
    {
        return $this->batch($resource, $ids, 'delete');
    }

    /**
     * The same values written to several records at once: validated once
     * as a partial update, then authorized, applied and hooked per record
     * exactly as update() does, and flushed in one transaction, so a
     * refusal or a conflict on any record changes none. Every record gets
     * its own audit entry with its own diff.
     *
     * @param list<string> $ids
     * @param array<string, mixed> $payload
     * @return int the number of records written
     */
    public function updateMany(ResourceDescriptor $resource, array $ids, array $payload): int
    {
        $ids = array_values(array_unique($ids));

        if (count($ids) > $resource->bulkLimit()) {
            throw new ForbiddenOperationException("At most {$resource->bulkLimit()} records can be handled at once.");
        }

        if ($payload === []) {
            throw ValidationException::fromViolations([new Violation(['fields'], 'required', 'must name at least one field to change.')]);
        }

        $policy = $this->policy($resource);
        $context = new WriteContext($resource, $this->user, $this->manager($resource), ['ids' => $ids, 'fields' => $payload]);
        $instance = $this->resource($resource);
        $before = [];
        $entities = [];
        $valuesOf = [];

        // validated per record: a field's conditions read that record's own values
        foreach ($ids as $id) {
            $current = $this->reader->show($resource, $id);
            $this->gate->authorize($this->user, static fn (CurrentUserInterface $u): bool|AuthorizationResponse => $policy->update($u, $current));
            $values = $this->input->validate($resource, $payload, true, $current);
            $with = array_keys(array_filter($values, static fn (mixed $v, string $name) => ($resource->field($name)['type'] ?? null) === 'manyToMany', ARRAY_FILTER_USE_BOTH));
            $entity = $this->load($resource, $id, $with);
            $this->authorizeMembership($resource, $entity, $current, $values);
            $before[$id] = $current;
            $entities[$id] = $entity;
            $valuesOf[$id] = $values;
        }

        foreach ($entities as $id => $entity) {
            $values = $valuesOf[$id];

            if (!$instance->apply($entity, $values, $context)) {
                $this->writer->assign($resource, $entity, $values);
            }

            if (($updated = $resource->timestamps()['updated']) !== null) {
                $this->writer->stamp($resource, $entity, $updated);
            }

            $instance->beforeUpdate($entity, $context);
        }

        $this->flush($resource);

        foreach ($entities as $entity) {
            $instance->afterUpdate($entity, $context);
        }

        if ($entities === []) {
            return 0;
        }

        $this->log($resource, 'update', array_keys($entities));
        $after = $this->reader->records($resource, array_keys($entities), scoped: false);

        foreach ($before as $id => $current) {
            $changes = self::diff(self::auditable($current), self::auditable($after[(string) $id] ?? $current));

            if ($changes !== []) {
                $this->audit->record($resource->slug(), [(string) $id], 'update', $changes, (string) $this->user->id(), $this->impersonator());
            }
        }

        return count($entities);
    }

    /**
     * A changeset from the list: different values for different records,
     * each checked against the version it was read at, authorized,
     * validated and hooked as update() does, all flushed in one
     * transaction. A violation's path is `changes/{index}/{field}`, and a
     * stale record fails the whole changeset with a conflict.
     *
     * @param list<array{id: int|string, version?: int|string|null, values: array<string, mixed>}> $changes
     * @return list<array<string, mixed>> the records after the write, in the changeset's order
     */
    public function applyChanges(ResourceDescriptor $resource, array $changes): array
    {
        if ($changes === [] || count($changes) > $resource->bulkLimit()) {
            throw ValidationException::fromViolations([new Violation(['changes'], 'type_mismatch', "must hold 1 to {$resource->bulkLimit()} changes.")]);
        }

        $policy = $this->policy($resource);
        $instance = $this->resource($resource);
        $violations = [];
        $prepared = [];

        foreach ($changes as $index => $change) {
            $id = (string) $change['id'];
            $current = $this->reader->show($resource, $id);
            $this->gate->authorize($this->user, static fn (CurrentUserInterface $u): bool|AuthorizationResponse => $policy->update($u, $current));

            try {
                $values = $this->input->validate($resource, $change['values'], true, $current);
            } catch (ValidationException $e) {
                foreach ($e->violations as $violation) {
                    $violations[] = $violation->under('changes', $index);
                }

                continue;
            }

            $with = array_keys(array_filter($values, static fn (mixed $v, string $name) => ($resource->field($name)['type'] ?? null) === 'manyToMany', ARRAY_FILTER_USE_BOTH));
            $entity = $this->load($resource, $id, $with);
            $version = $change['version'] ?? null;

            if ($resource->versionField() !== null && $version !== null && (string) $this->writer->read($entity, $resource->versionField()) !== (string) $version) {
                throw ConflictException::stale($resource->slug());
            }

            $this->authorizeMembership($resource, $entity, $current, $values);
            $prepared[] = ['id' => $id, 'entity' => $entity, 'values' => $values, 'before' => $current, 'context' => new WriteContext($resource, $this->user, $this->manager($resource), $change['values'])];
        }

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        foreach ($prepared as $item) {
            if (!$instance->apply($item['entity'], $item['values'], $item['context'])) {
                $this->writer->assign($resource, $item['entity'], $item['values']);
            }

            if (($updated = $resource->timestamps()['updated']) !== null) {
                $this->writer->stamp($resource, $item['entity'], $updated);
            }

            $instance->beforeUpdate($item['entity'], $item['context']);
        }

        try {
            $this->flush($resource);
        } catch (ValidationException $e) {
            // the database names the column, never the row: a field only one change writes is that change's
            $violations = [];

            foreach ($e->violations as $violation) {
                $field = $violation->path[0] ?? null;
                $writers = array_keys(array_filter($changes, static fn (array $change): bool => is_string($field) && array_key_exists($field, $change['values'])));
                $violations[] = count($writers) === 1 ? $violation->under('changes', $writers[0]) : $violation;
            }

            throw ValidationException::fromViolations($violations);
        }

        foreach ($prepared as $item) {
            $instance->afterUpdate($item['entity'], $item['context']);
        }

        $ids = array_column($prepared, 'id');
        $this->log($resource, 'update', $ids);
        $after = $this->reader->records($resource, $ids, scoped: false);
        $records = [];

        foreach ($prepared as $item) {
            $record = $after[$item['id']] ?? $item['before'];
            $changes = self::diff(self::auditable($item['before']), self::auditable($record));

            if ($changes !== []) {
                $this->audit->record($resource->slug(), [$item['id']], 'update', $changes, (string) $this->user->id(), $this->impersonator());
            }

            $records[] = $record;
        }

        return $records;
    }

    /**
     * Adds records to one many-to-many relation of a record. Each added
     * record needs the policy's attach(); ids already present are
     * skipped. The owner's update hooks run, the log and the audit record
     * the change.
     *
     * @param list<int|string> $ids
     * @return array<string, mixed> the owner, serialized after the write
     */
    public function attach(ResourceDescriptor $resource, string $id, string $relation, array $ids): array
    {
        return $this->changeMembership($resource, $id, $relation, $ids, 'attach');
    }

    /**
     * Removes records from one many-to-many relation of a record. Each
     * removed record needs the policy's detach(); ids not present are
     * skipped.
     *
     * @param list<int|string> $ids
     * @return array<string, mixed> the owner, serialized after the write
     */
    public function detach(ResourceDescriptor $resource, string $id, string $relation, array $ids): array
    {
        return $this->changeMembership($resource, $id, $relation, $ids, 'detach');
    }

    /**
     * @param list<int|string> $ids
     * @param 'attach'|'detach' $verb
     * @return array<string, mixed>
     */
    private function changeMembership(ResourceDescriptor $resource, string $id, string $relation, array $ids, string $verb): array
    {
        $field = $resource->field($relation);

        if (($field['type'] ?? null) !== 'manyToMany' || !in_array('update', $field['views'], true) || $field['readonly'] === true) {
            throw ValidationException::fromViolations([new Violation([$relation], 'unexpected_field', 'is not a many-to-many field this resource edits.')]);
        }

        if ($ids === [] || count($ids) > $resource->bulkLimit()) {
            throw ValidationException::fromViolations([new Violation(['ids'], 'type_mismatch', "must be a list of 1 to {$resource->bulkLimit()} identifiers.")]);
        }

        $current = $this->reader->show($resource, $id);
        $entity = $this->load($resource, $id, [$relation]);
        $target = $this->reader->resource($field['relation']['resource']);
        $key = $target->primaryKeyField();

        try {
            $named = $this->converter->convert($field, $ids);
        } catch (InputViolation $e) {
            throw ValidationException::fromViolations([$e->violation->under('ids')]);
        }

        /** @var list<object> $named */
        $existing = $this->writer->read($entity, $relation);
        $existing = is_iterable($existing) ? array_values(is_array($existing) ? $existing : iterator_to_array($existing)) : [];
        $idOf = fn (object $e): string => (string) $this->writer->read($e, $key);
        $present = array_map($idOf, $existing);
        $list = $verb === 'attach'
            ? [...$existing, ...array_values(array_filter($named, static fn (object $e) => !in_array($idOf($e), $present, true)))]
            : array_values(array_filter($existing, static fn (object $e) => !in_array($idOf($e), array_map($idOf, $named), true)));

        if (count($list) === count($existing)) {
            return $current; // nothing to add or remove
        }

        $values = [$relation => $list];
        $this->authorizeMembership($resource, $entity, $current, $values);
        $context = new WriteContext($resource, $this->user, $this->manager($resource), [$relation => $ids, 'operation' => $verb]);
        $instance = $this->resource($resource);
        $this->writer->assign($resource, $entity, $values);

        if (($updated = $resource->timestamps()['updated']) !== null) {
            $this->writer->stamp($resource, $entity, $updated);
        }

        $instance->beforeUpdate($entity, $context);
        $this->flush($resource);
        $instance->afterUpdate($entity, $context);
        $this->log($resource, "{$verb}:{$relation}", [$id]);
        $record = $this->reader->show($resource, $id);
        $this->audit->record($resource->slug(), [$id], $verb, [$relation => ['from' => $present, 'to' => array_map($idOf, $list)]], (string) $this->user->id(), $this->impersonator());

        return $record;
    }

    /**
     * One bulk operation over identifiers: delete, restore or
     * forceDelete. Every record is authorized before anything changes,
     * the whole batch is one flush, and the log carries every id.
     *
     * @param list<string> $ids
     * @param 'delete'|'restore'|'forceDelete' $operation
     * @return int how many records changed
     */
    public function batch(ResourceDescriptor $resource, array $ids, string $operation): int
    {
        $soft = $resource->softDelete();

        if ($soft === null && $operation !== 'delete') {
            throw new ForbiddenOperationException("{$resource->slug()} does not soft-delete; only delete applies.");
        }

        $policy = $this->policy($resource);
        $decide = match ($operation) {
            'delete' => $policy->delete(...),
            'restore' => $policy->restore(...),
            'forceDelete' => $policy->forceDelete(...),
        };
        $removes = $soft === null || $operation === 'forceDelete';
        $soft ??= ['field' => '', 'column' => ''];
        $entities = [];
        $ids = array_values(array_unique($ids));

        if (count($ids) > $resource->bulkLimit()) {
            throw new ForbiddenOperationException("At most {$resource->bulkLimit()} records can be handled at once.");
        }

        foreach ($ids as $id) {
            $current = $this->reader->show($resource, $id);
            $this->gate->authorize($this->user, static fn (CurrentUserInterface $u): bool|AuthorizationResponse => $decide($u, $current));

            if ($operation === 'restore' && ($current['_trashed'] ?? false) !== true) {
                continue; // nothing to restore
            }

            if ($operation === 'delete' && ($current['_trashed'] ?? false) === true) {
                continue; // already in the trash
            }

            $entities[$id] = $this->load($resource, $id, $removes ? $resource->ownedRelations() : []);
        }

        $context = new WriteContext($resource, $this->user, $this->manager($resource), ['ids' => $ids, 'operation' => $operation]);
        $instance = $this->resource($resource);

        foreach ($entities as $entity) {
            if ($removes) {
                $instance->beforeDelete($entity, $context);
                $this->manager($resource)->remove($entity);
            } elseif ($operation === 'delete') {
                $instance->beforeDelete($entity, $context);
                $this->writer->stamp($resource, $entity, $soft['field']);
            } else {
                $this->writer->assign($resource, $entity, [$soft['field'] => null]);
            }

            if (!$removes && ($updated = $resource->timestamps()['updated']) !== null) {
                $this->writer->stamp($resource, $entity, $updated);
            }
        }

        $this->flush($resource);

        foreach ($entities as $entity) {
            if ($operation !== 'restore') {
                $instance->afterDelete($entity, $context);
            }
        }

        if ($entities !== []) {
            $this->log($resource, $operation, array_keys($entities));
            $this->audit->record($resource->slug(), array_map(strval(...), array_keys($entities)), $operation, [], (string) $this->user->id(), $this->impersonator());
        }

        return count($entities);
    }

    /**
     * A many-to-many field written in full is a set of attaches and
     * detaches: each added record needs the policy's attach(), each
     * removed one its detach(), before anything is assigned.
     *
     * @param array<string, mixed> $current the record as serialized before the write
     * @param array<string, mixed> $values validated values, related entities for relation fields
     */
    private function authorizeMembership(ResourceDescriptor $resource, object $entity, array $current, array $values): void
    {
        $policy = $this->policy($resource);

        foreach ($values as $name => $related) {
            $field = $resource->field($name);

            if (($field['type'] ?? null) !== 'manyToMany' || !is_array($related)) {
                continue;
            }

            $target = $this->reader->resource($field['relation']['resource']);
            $key = $target->primaryKeyField();
            $idsOf = function (mixed $entities) use ($key): array {
                return is_iterable($entities)
                    ? array_values(array_map(fn (object $e): string => (string) $this->writer->read($e, $key), is_array($entities) ? $entities : iterator_to_array($entities)))
                    : [];
            };
            $before = $idsOf($this->writer->read($entity, $name));
            $after = $idsOf($related);
            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));

            if ($added === [] && $removed === []) {
                continue;
            }

            $records = $this->reader->records($target, [...$added, ...$removed]);

            $changes = [
                ...array_map(static fn (string $id): array => [$id, 'attach'], $added),
                ...array_map(static fn (string $id): array => [$id, 'detach'], $removed),
            ];

            foreach ($changes as [$id, $verb]) {
                $record = $records[(string) $id] ?? [$key => $id];
                $this->gate->authorize(
                    $this->user,
                    static fn (CurrentUserInterface $u): bool|AuthorizationResponse => $verb === 'attach'
                        ? $policy->attach($u, $current, $name, $record)
                        : $policy->detach($u, $current, $name, $record),
                );
            }
        }
    }

    /** @param list<string> $with */
    private function load(ResourceDescriptor $resource, string $id, array $with): object
    {
        $query = $this->manager($resource)->repository($resource->entityClass())->query()
            ->where($resource->primaryKeyField(), '=', $resource->primaryKeyType() === 'int' ? (int) $id : $id);

        if ($with !== []) {
            $query->with(...$with);
        }

        return $query->first() ?? throw RecordNotFoundException::of($resource->slug(), $id);
    }

    /**
     * One flush; a unique-key conflict becomes a 422 on the field whose
     * index refused it, a stale versioned row a 409.
     */
    private function flush(ResourceDescriptor $resource): void
    {
        try {
            $this->manager($resource)->flush();
        } catch (OptimisticLockException) {
            throw ConflictException::stale($resource->slug());
        } catch (QueryException $e) {
            // a value its column cannot hold: too long (22001) or out of range (22003)
            if (in_array($e->getSqlState(), ['22001', '22003'], true)) {
                $long = $e->getSqlState() === '22001';
                $field = preg_match("/column '([^']+)'/", $e->getMessage(), $m) === 1 ? array_search($m[1], $resource->columns(), true) : false;

                throw ValidationException::fromViolations([new Violation(
                    $field === false ? [] : [(string) $field],
                    $long ? 'too_long' : 'out_of_range',
                    $long ? 'is longer than the database column allows.' : 'is outside the range the database column allows.',
                )]);
            }

            if (!$e->isUniqueViolation()) {
                throw $e;
            }

            $field = self::uniqueField($resource, $e->getMessage());

            if ($field !== null) {
                throw ValidationException::fromViolations([new Violation([$field], 'unique', 'is already taken.')]);
            }

            throw ValidationException::fromViolations([new Violation([], 'unique', 'conflicts with an existing record.')]);
        }
    }

    /**
     * The field whose unique index refused a write: PostgreSQL names the
     * column (`Key (sku)=(…) already exists`), MySQL the index
     * (`for key 'products.products_sku_unique'`), which by convention
     * contains it. Longer column names are tried first so `slug` never
     * claims `category_slug`.
     */
    private static function uniqueField(ResourceDescriptor $resource, string $message): ?string
    {
        $columns = $resource->columns();

        if (preg_match('/Key \(([^)]+)\)=/', $message, $m) === 1) {
            $field = array_search(trim(explode(',', $m[1])[0], ' "'), $columns, true);

            if (is_string($field)) {
                return $field;
            }
        }

        // MySQL's message carries the duplicate value too (`Duplicate entry 'name' for key '…'`):
        // only the index name is searched, so a submitted value never names another field
        if (preg_match("/for key '([^']*)'/", $message, $m) === 1) {
            $message = $m[1];
        }

        uasort($columns, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($columns as $field => $column) {
            if (preg_match('/(?<![a-z0-9])' . preg_quote($column, '/') . '(?![a-z0-9])/i', $message) === 1) {
                return $field;
            }
        }

        return null;
    }

    /** The request's manager for the connection $resource's entity lives on. */
    private function manager(ResourceDescriptor $resource): EntityManager
    {
        return $this->managers->managerFor($resource->entityClass());
    }

    private function resource(ResourceDescriptor $resource): VectorResource
    {
        $instance = $this->scope->get($resource->resourceClass());

        return $instance instanceof VectorResource ? $instance : throw new \LogicException("{$resource->resourceClass()} is not a VectorResource.");
    }

    private function policy(ResourceDescriptor $resource): \Kinetis\Vector\Auth\ResourcePolicyInterface
    {
        return $this->reader->policy($resource);
    }

    /**
     * The entities a list of identifiers names, loaded for an action.
     *
     * @param list<string> $ids
     * @return array<string, object>
     */
    public function loadMany(ResourceDescriptor $resource, array $ids): array
    {
        $entities = [];

        foreach ($ids as $id) {
            $entities[$id] = $this->load($resource, $id, []);
        }

        return $entities;
    }

    /**
     * Flushes what an action changed and logs it.
     *
     * @param list<int|string|null> $ids
     */
    public function commitAction(ResourceDescriptor $resource, string $action, array $ids): void
    {
        $this->flush($resource);
        $this->log($resource, "action:{$action}", $ids);

        if ($ids !== []) {
            $this->audit->record($resource->slug(), array_map(strval(...), $ids), "action:{$action}", [], (string) $this->user->id(), $this->impersonator());
        }
    }

    /** Whoever is impersonating the user, under HTTP or in a job the impersonator started. */
    private function impersonator(): ?string
    {
        return $this->actor->impersonatorId;
    }

    /**
     * A serialized value as the audit trail stored it: the trail keeps
     * JSON decoded to arrays, while a record carries an empty key-value
     * map as an object, and `{}` and `[]` must compare equal.
     */
    private static function comparable(mixed $value): string
    {
        return json_encode(json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);
    }

    /**
     * The serialized record without what is not a field value.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private static function auditable(array $record): array
    {
        unset($record['_trashed'], $record['_version']);

        return $record;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private static function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $field => $value) {
            $previous = $before[$field] ?? null;

            if (json_encode($previous, JSON_THROW_ON_ERROR) !== json_encode($value, JSON_THROW_ON_ERROR)) {
                $changes[$field] = ['from' => $previous, 'to' => $value];
            }
        }

        return $changes;
    }

    /** @param list<int|string|null> $ids */
    private function log(ResourceDescriptor $resource, string $action, array $ids): void
    {
        $this->vector->query()->table('vector_action_events')->insert([
            'actor_id' => (string) $this->user->id(),
            'impersonator_id' => $this->impersonator(),
            'resource' => $resource->slug(),
            'action' => $action,
            'record_ids' => json_encode(array_map(strval(...), $ids), JSON_THROW_ON_ERROR),
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        ]);
    }
}
