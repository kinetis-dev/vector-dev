<?php

declare(strict_types=1);

namespace Kinetis\Vector\Actions;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Fields\Field;

/**
 * One operation an admin runs on records — one, a selection, or every
 * record a filter matched — or on nothing at all when `standalone()`.
 *
 * The definition methods run at compile time on an instance built
 * without arguments, exactly like a resource's: they return plain data
 * and never touch a connection. `canRun()` and `handle()` run at request
 * time on the instance the request container builds, so the constructor
 * may inject what a controller may.
 */
abstract class VectorAction
{
    /** The URL segment and the name the descriptor publishes; kebab-case of the class name by default. */
    public function name(): string
    {
        $short = (new \ReflectionClass($this))->getShortName();
        $short = preg_replace('/Action$/', '', $short) ?? $short;

        return strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $short) ?? $short);
    }

    public function label(): string
    {
        return Field::labelFrom(str_replace('-', '_', $this->name()));
    }

    public function icon(): ?string
    {
        return null;
    }

    /**
     * The inputs the action asks for in its dialog, compiled like a
     * resource's fields: scalar, enum and belongsTo fields only.
     *
     * @return list<Field>
     */
    public function fields(): array
    {
        return [];
    }

    /** The question a confirmation dialog asks; null asks nothing unless the action is destructive. */
    public function confirmation(): ?string
    {
        return null;
    }

    public function destructive(): bool
    {
        return false;
    }

    /** True for an action that runs without records (a report, an import, a recalculation). */
    public function standalone(): bool
    {
        return false;
    }

    /**
     * True runs the action in a background job through kinetis/queue:
     * the request answers with a job the client polls, and the action
     * handles the selection in chunks of `chunkSize()` records, each
     * chunk a fresh ActionRequest and one flush. Needs QUEUE_CONNECTION
     * and an ActorResolverInterface bound by the application.
     */
    public function queued(): bool
    {
        return false;
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function showOnIndex(): bool
    {
        return true;
    }

    public function showOnDetail(): bool
    {
        return true;
    }

    /**
     * Request-time visibility. With a record, whether this user may run
     * the action on that record (the show page asks); without one,
     * whether the action is offered at all (the descriptor and the
     * bulk bar ask). The policy's viewAny already admitted the user.
     *
     * @param array<string, mixed>|null $record the serialized record
     */
    public function canRun(CurrentUserInterface $user, ?array $record): bool
    {
        return true;
    }

    abstract public function handle(ActionRequest $request): ActionResponse;
}
