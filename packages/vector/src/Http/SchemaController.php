<?php

declare(strict_types=1);

namespace Kinetis\Vector\Http;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\JsonResponse;
use Kinetis\Http\Responses\PlainTextResponse;
use Kinetis\Container\RequestScope;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Settings\SettingsGroup;
use Kinetis\Vector\Store\PreferenceStore;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Store\NotificationStore;
use Kinetis\Vector\Store\SettingsStore;
use Kinetis\Vector\Auth\ImpersonatedUserInterface;
use Kinetis\Vector\Auth\ImpersonationInterface;
use Kinetis\Vector\Widgets\VectorDashboard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The descriptor, overlaid with what this user may do. Resources the
 * user may not list are absent, not marked. The ETag covers the overlay,
 * so a client caches per identity.
 */
#[RoutePrefix('/vector')]
#[Middleware('@vector')]
final readonly class SchemaController
{
    public function __construct(
        private ResourceService $service,
        private CurrentUserInterface $user,
        private SettingsStore $settings,
        private PreferenceStore $preferences,
        private RequestScope $scope,
        private NotificationStore $notifications,
    ) {}

    #[Get('/schema')]
    public function schema(ServerRequestInterface $request): ResponseInterface
    {
        $resources = [];
        $menu = [];

        foreach ($this->service->resources() as $slug => $descriptor) {
            $permissions = $this->service->permissions($descriptor);

            if ($permissions['viewAny'] !== true) {
                continue;
            }

            $resources[$slug] = $this->overlay($descriptor, $permissions);
            $menu[$descriptor->toArray()['group'] ?? 'Resources'][] = $slug;
        }

        ksort($menu, SORT_STRING);

        $registry = $this->service->registry();
        $groups = [];

        foreach ($registry->settings() as $name => $group) {
            $instance = $this->scope->get($group->groupClass());

            if ($instance instanceof SettingsGroup && $instance->canManage($this->user)) {
                $groups[] = ['name' => $name, 'label' => $group->label(), 'icon' => $group->toArray()['icon']];
            }
        }

        $dashboards = [];

        foreach ($registry->dashboards() as $name => $dashboard) {
            $instance = $this->scope->get($dashboard['class']);

            if ($instance instanceof VectorDashboard && $instance->canView($this->user)) {
                $dashboards[] = ['name' => $name, 'label' => $dashboard['label'], 'icon' => $dashboard['icon']];
            }
        }

        $document = [
            'version' => $registry->toArray()['version'],
            'resources' => (object) $resources,
            'menu' => array_map(
                static fn (string $group, array $slugs): array => ['label' => $group, 'resources' => $slugs],
                array_keys($menu),
                array_values($menu),
            ),
            'settings' => $groups,
            'dashboards' => $dashboards,
            'capabilities' => [
                'impersonation' => $this->scope->has(ImpersonationInterface::class) && $this->impersonation()?->canImpersonate($this->user) === true,
                'queue' => $this->vectorQueue()->available(),
                'uploads' => $this->scope->get(\Kinetis\Vector\Files\VectorStorage::class) instanceof \Kinetis\Vector\Files\VectorStorage
                    && $this->scope->get(\Kinetis\Vector\Files\VectorStorage::class)->available(),
            ],
            'branding' => isset($registry->settings()['branding']) ? $this->settings->values($registry->settingsGroup('branding')) : null,
            // a money value is in its currency's minor units: these codes are not hundredths
            'minorUnits' => \Kinetis\Vector\Fields\Currency::MINOR_UNITS,
        ];

        return $this->cached($request, $document);
    }

    #[Get('/schema/{resource}')]
    public function resource(string $resource, ServerRequestInterface $request): ResponseInterface
    {
        $descriptor = $this->service->resource($resource);
        $permissions = $this->service->permissions($descriptor);

        if ($permissions['viewAny'] !== true) {
            return JsonResponse::create(['error' => 'This account may not view this resource.'], 403);
        }

        return $this->cached($request, $this->overlay($descriptor, $permissions));
    }

    /** @return array<string, mixed> */
    #[Get('/me')]
    public function me(): array
    {
        $impersonator = $this->user instanceof ImpersonatedUserInterface ? $this->user->impersonatorId() : null;

        return [
            'id' => $this->user->id(),
            'name' => $this->user instanceof VectorShellUserInterface ? $this->user->displayName() : null,
            'preferences' => $this->preferences->all((string) $this->user->id()),
            'impersonator' => $impersonator,
            'unreadNotifications' => $this->notifications->inbox((string) $this->user->id(), true, 1)['unread'],
        ];
    }

    private function vectorQueue(): \Kinetis\Vector\Jobs\VectorQueue
    {
        $queue = $this->scope->get(\Kinetis\Vector\Jobs\VectorQueue::class);

        return $queue instanceof \Kinetis\Vector\Jobs\VectorQueue ? $queue : throw new \LogicException('VectorQueue is not bound.');
    }

    private function impersonation(): ?ImpersonationInterface
    {
        $service = $this->scope->get(ImpersonationInterface::class);

        return $service instanceof ImpersonationInterface ? $service : null;
    }

    /**
     * The published descriptor with this user's overlay: the permissions
     * and only the actions this user may run.
     *
     * @param array<string, bool> $permissions
     * @return array<string, mixed>
     */
    private function overlay(ResourceDescriptor $descriptor, array $permissions): array
    {
        $public = $descriptor->toArray();
        $visible = $this->service->visibleActions($descriptor, null);
        $public['actions'] = array_values(array_filter($public['actions'], static fn (array $a): bool => in_array($a['name'], $visible, true)));

        return [...$public, 'permissions' => $permissions];
    }

    /** @param array<string, mixed> $document */
    private function cached(ServerRequestInterface $request, array $document): ResponseInterface
    {
        $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $etag = '"' . hash('xxh128', $json . '|' . $this->user->id()) . '"';

        if (in_array($etag, array_map('trim', explode(',', $request->getHeaderLine('If-None-Match'))), true)) {
            return PlainTextResponse::create('', 304, ['ETag' => $etag]);
        }

        return JsonResponse::create($document, 200, ['ETag' => $etag, 'Cache-Control' => 'private, no-cache']);
    }
}
