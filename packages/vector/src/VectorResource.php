<?php

declare(strict_types=1);

namespace Kinetis\Vector;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Auth\ResourcePolicyInterface;
use Kinetis\Vector\Fields\Field;
use Kinetis\Vector\Write\WriteContext;

/**
 * The base of every admin resource. The definition methods run at
 * compile time on an instance built without arguments, so they return
 * plain field objects and never touch a connection, a request or a
 * user. The hook methods run at request time on an instance the request
 * container builds, so they may depend on anything a controller may.
 */
abstract class VectorResource
{
    /**
     * The fields to administer. An empty list means "derive every field
     * from the entity's metadata"; a non-empty list replaces derivation.
     *
     * @return list<Field>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * The policy answering viewAny/view/create/update/delete for this
     * resource, or null for the default that admits every user the
     * access policy admitted.
     *
     * @return class-string<ResourcePolicyInterface>|null
     */
    public function policy(): ?string
    {
        return null;
    }

    public function perPage(): int
    {
        return 25;
    }

    /** @return list<int> */
    public function perPageOptions(): array
    {
        return [10, 25, 50, 100];
    }

    public function maxPerPage(): int
    {
        return 200;
    }

    /**
     * The order a list takes when the request names none. Field names,
     * `-` for descending. Empty means the identifier, ascending.
     *
     * @return list<string>
     */
    public function defaultSort(): array
    {
        return [];
    }

    /**
     * The timestamp fields Vector maintains: set on create, and the
     * second one on every update. Null derives them from properties
     * named `createdAt` and `updatedAt`; `['created' => null, 'updated'
     * => null]` maintains none.
     *
     * @return array{created: string|null, updated: string|null}|null
     */
    public function timestamps(): ?array
    {
        return null;
    }

    /**
     * The nullable DateTimeImmutable property that marks a deleted row.
     * Set, a delete stamps it instead of removing the row, lists hide
     * stamped rows unless `filter[trashed]` says otherwise, and restore
     * and force-delete become available. Null removes rows.
     */
    public function softDeletes(): ?string
    {
        return null;
    }

    /**
     * The actions this resource offers, as classes the request container
     * builds when one runs.
     *
     * @return list<class-string<\Kinetis\Vector\Actions\VectorAction>>
     */
    public function actions(): array
    {
        return [];
    }

    /**
     * The widgets shown above this resource's list.
     *
     * @return list<class-string<\Kinetis\Vector\Widgets\VectorWidget>>
     */
    public function widgets(): array
    {
        return [];
    }

    /** Whether `GET /vector/search` covers this resource (it needs searchable fields either way). */
    public function globallySearchable(): bool
    {
        return true;
    }

    /**
     * The field a global search result shows under its title — a text,
     * number or enum field, such as a SKU or an e-mail — or null for none.
     */
    public function searchSubtitle(): ?string
    {
        return null;
    }

    /** The image field a global search result shows as its thumbnail, or null for none. */
    public function searchImage(): ?string
    {
        return null;
    }

    /** The count the menu shows beside this resource, or null for none. */
    public function badge(): ?Badge
    {
        return null;
    }

    /** The most rows an inline CSV export serializes; a larger match is refused with 422. */
    public function exportLimit(): int
    {
        return 10000;
    }

    /** The most rows a queued export serializes; a larger match is refused. */
    public function exportQueueLimit(): int
    {
        return 250000;
    }

    /** The most records one bulk operation or action touches, by ids or by "all matching". */
    public function bulkLimit(): int
    {
        return 1000;
    }

    /**
     * Request-time hook: restricts every list and show query for this
     * user. The query already selects from the resource's table.
     */
    public function scope(Query $query, CurrentUserInterface $user): Query
    {
        return $query;
    }

    /**
     * Request-time hook: builds a new entity from validated, converted
     * values (enum cases, DateTimeImmutable instants, related entities).
     * Null hands the job to the default writer, which allocates the
     * entity without its constructor and assigns every mapped property.
     *
     * @param array<string, mixed> $values
     */
    public function create(array $values, WriteContext $context): ?object
    {
        return null;
    }

    /**
     * Request-time hook: applies validated, converted values to a loaded
     * entity. Returning false hands the job to the default writer.
     *
     * @param array<string, mixed> $values
     */
    public function apply(object $entity, array $values, WriteContext $context): bool
    {
        return false;
    }

    /** Request-time hooks around a write, after validation and before the flush. */
    public function beforeCreate(object $entity, WriteContext $context): void {}

    public function beforeUpdate(object $entity, WriteContext $context): void {}

    public function beforeDelete(object $entity, WriteContext $context): void {}

    /** Request-time hooks after the flush committed. */
    public function afterCreate(object $entity, WriteContext $context): void {}

    public function afterUpdate(object $entity, WriteContext $context): void {}

    public function afterDelete(object $entity, WriteContext $context): void {}
}
