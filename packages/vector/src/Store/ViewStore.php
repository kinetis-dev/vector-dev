<?php

declare(strict_types=1);

namespace Kinetis\Vector\Store;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\QueryBuilder\Query;

/**
 * `vector_views`: saved list configurations — filters, search, sort,
 * columns, grouping, page size — per resource. A view is personal to
 * its owner or shared with every admin; its owner alone changes it. A
 * user's default view for a resource is the one the list opens with; one
 * shared view per resource can be the default for everyone else.
 *
 * @phpstan-type ViewRow array{id: int, resource: string, ownerId: string, name: string, shared: bool, isDefault: bool, resourceDefault: bool, state: array<string, mixed>, mine: bool}
 */
final readonly class ViewStore
{
    public const int MAX_STATE_BYTES = 16384;

    public function __construct(private VectorDatabase $db) {}

    /** @return list<ViewRow> the owner's own views and the shared ones, by name */
    public function forResource(string $resource, string $ownerId): array
    {
        $rows = $this->query()->table('vector_views')
            ->where('resource', '=', $resource)
            ->whereGroup(static fn ($c) => $c->where('owner_id', '=', $ownerId)->orWhere('shared', '=', 1))
            ->orderBy('name', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        return array_map(fn (array $row): array => $this->view($row, $ownerId), $rows);
    }

    /** @return ViewRow|null */
    public function find(int $id, string $ownerId): ?array
    {
        $row = $this->query()->table('vector_views')->where('id', '=', $id)->first();

        return is_array($row) ? $this->view($row, $ownerId) : null;
    }

    /** @param array<string, mixed> $state */
    public function create(string $resource, string $ownerId, string $name, bool $shared, bool $isDefault, array $state, bool $resourceDefault = false): int
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        if ($isDefault) {
            $this->clearDefault($resource, $ownerId);
        }

        if ($resourceDefault) {
            $this->clearResourceDefault($resource);
        }

        $id = $this->query()->table('vector_views')->insertGetId([
            'resource' => $resource,
            'owner_id' => $ownerId,
            'name' => $name,
            'shared' => $shared ? 1 : 0,
            'is_default' => $isDefault ? 1 : 0,
            'resource_default' => $resourceDefault ? 1 : 0,
            'state' => json_encode($state, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $id;
    }

    /** @param array<string, mixed> $state */
    public function update(int $id, string $resource, string $ownerId, string $name, bool $shared, bool $isDefault, array $state, bool $resourceDefault = false): void
    {
        if ($isDefault) {
            $this->clearDefault($resource, $ownerId);
        }

        if ($resourceDefault) {
            $this->clearResourceDefault($resource);
        }

        $this->query()->table('vector_views')->where('id', '=', $id)->update([
            'name' => $name,
            'shared' => $shared ? 1 : 0,
            'is_default' => $isDefault ? 1 : 0,
            'resource_default' => $resourceDefault ? 1 : 0,
            'state' => json_encode($state, JSON_THROW_ON_ERROR),
            'updated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function delete(int $id): void
    {
        $this->query()->table('vector_views')->where('id', '=', $id)->delete();
    }

    private function clearDefault(string $resource, string $ownerId): void
    {
        $this->query()->table('vector_views')
            ->where('resource', '=', $resource)
            ->where('owner_id', '=', $ownerId)
            ->where('is_default', '=', 1)
            ->update(['is_default' => 0]);
    }

    private function clearResourceDefault(string $resource): void
    {
        $this->query()->table('vector_views')
            ->where('resource', '=', $resource)
            ->where('resource_default', '=', 1)
            ->update(['resource_default' => 0]);
    }

    /**
     * @param array<string, mixed> $row
     * @return ViewRow
     */
    private function view(array $row, string $ownerId): array
    {
        $state = json_decode((string) $row['state'], true, 16, JSON_THROW_ON_ERROR);

        return [
            'id' => (int) $row['id'],
            'resource' => (string) $row['resource'],
            'ownerId' => (string) $row['owner_id'],
            'name' => (string) $row['name'],
            'shared' => (int) $row['shared'] === 1,
            // a shared view's default flag is its owner's, not everyone's
            'isDefault' => (int) $row['is_default'] === 1 && (string) $row['owner_id'] === $ownerId,
            // everyone's default, for whoever has none of their own; only a shared view can be
            'resourceDefault' => (int) $row['resource_default'] === 1 && (int) $row['shared'] === 1,
            'state' => is_array($state) ? $state : [],
            'mine' => (string) $row['owner_id'] === $ownerId,
        ];
    }

    private function query(): Query
    {
        return $this->db->query();
    }
}
