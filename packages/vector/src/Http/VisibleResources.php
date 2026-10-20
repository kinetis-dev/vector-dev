<?php

declare(strict_types=1);

namespace Kinetis\Vector\Http;

use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\ResourceDescriptor;

/**
 * The resources this request's user may list, each as they see it, and
 * the overlay the descriptor publishes for one. The descriptor and the
 * OpenAPI document both read through here, so neither can show a
 * resource, a field or an action the other hides.
 */
final readonly class VisibleResources
{
    public function __construct(private ResourceService $service) {}

    /**
     * Every resource whose list this user may see — without the fields
     * its policy hides from them — with their permissions on it.
     *
     * @return array<string, array{descriptor: ResourceDescriptor, permissions: array<string, bool>}>
     */
    public function all(): array
    {
        $visible = [];

        foreach ($this->service->resources() as $slug => $descriptor) {
            $permissions = $this->service->permissions($descriptor);

            if ($permissions['viewAny'] === true) {
                $visible[$slug] = ['descriptor' => $descriptor, 'permissions' => $permissions];
            }
        }

        return $visible;
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
