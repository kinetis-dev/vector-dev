<?php

declare(strict_types=1);

namespace Kinetis\Vector\Http;

use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\ResourceDescriptor;

/**
 * The resources this request's user may list, each as they see it, and
 * the overlay the descriptor publishes for one. The descriptor and the
 * OpenAPI document both read through here, so neither can show a
 * resource, a field or an action the other hides; a shell contributor
 * may read the same list, which all() decides once per request. Built
 * per request: the user is this request's.
 */
final readonly class VisibleResources
{
    /**
     * all()'s answer, under `all`, once it was asked: this instance's own,
     * and so one request's, never shared.
     *
     * @var \ArrayObject<'all', array<string, array{descriptor: ResourceDescriptor, permissions: array<string, bool>}>>
     */
    private \ArrayObject $decided;

    public function __construct(private ResourceService $service)
    {
        /** @var \ArrayObject<'all', array<string, array{descriptor: ResourceDescriptor, permissions: array<string, bool>}>> $decided */
        $decided = new \ArrayObject();
        $this->decided = $decided;
    }

    /**
     * Every resource whose list this user may see (without the fields
     * its policy hides from them) with their permissions on it. Decided
     * once per request: asked again, by the descriptor, the OpenAPI
     * document or a shell contributor, it asks no policy again.
     *
     * @return array<string, array{descriptor: ResourceDescriptor, permissions: array<string, bool>}>
     */
    public function all(): array
    {
        if (isset($this->decided['all'])) {
            return $this->decided['all'];
        }

        $visible = [];

        foreach ($this->service->resources() as $slug => $descriptor) {
            $permissions = $this->service->permissions($descriptor);

            if ($permissions['viewAny'] === true) {
                $visible[$slug] = ['descriptor' => $descriptor, 'permissions' => $permissions];
            }
        }

        $this->decided['all'] = $visible;

        return $visible;
    }

    /**
     * $slug as this user sees it, when all() has already decided this
     * request's list and it is on it; null otherwise, asks no policy, so a
     * caller that wants one resource is not made to pay for every one.
     */
    public function decided(string $slug): ?ResourceDescriptor
    {
        return isset($this->decided['all']) ? ($this->decided['all'][$slug]['descriptor'] ?? null) : null;
    }

    /**
     * The published descriptor with this user's overlay: the permissions
     * and only the actions this user may run.
     *
     * @param array<string, bool> $permissions
     * @return array<string, mixed>
     */
    public function overlay(ResourceDescriptor $descriptor, array $permissions): array
    {
        $public = $descriptor->toArray();
        $visible = $this->service->visibleActions($descriptor, null);
        $public['actions'] = array_values(array_filter($public['actions'], static fn (array $a): bool => in_array($a['name'], $visible, true)));

        return [...$public, 'permissions' => $permissions];
    }
}
