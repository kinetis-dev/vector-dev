<?php

declare(strict_types=1);

namespace Kinetis\Vector\Http;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Vector\Exception\ForbiddenOperationException;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Widgets\WidgetRunner;
use Psr\Http\Message\ServerRequestInterface;

/** Dashboards: the cards a page holds, and each card's data for a range. */
#[RoutePrefix('/vector')]
#[Middleware('@vector')]
final readonly class DashboardController
{
    public function __construct(
        private VectorRegistry $registry,
        private WidgetRunner $widgets,
    ) {}

    /** @return array{data: array<string, mixed>} */
    #[Get('/dashboards/{name}')]
    public function show(string $name): array
    {
        $dashboard = $this->admitted($name);

        return ['data' => ['name' => $name, 'label' => $dashboard['label'], 'icon' => $dashboard['icon'], 'widgets' => $this->widgets->visible($dashboard['widgets'])]];
    }

    /** @return array{data: array<string, mixed>} */
    #[Get('/dashboards/{name}/widgets/{widget}')]
    public function widget(string $name, string $widget, ServerRequestInterface $request): array
    {
        $dashboard = $this->admitted($name);
        $compiled = null;

        foreach ($dashboard['widgets'] as $candidate) {
            if ($candidate['name'] === $widget) {
                $compiled = $candidate;
            }
        }

        $compiled ??= throw WidgetRunner::notFound($widget);
        $range = $request->getQueryParams()['range'] ?? null;

        return ['data' => $this->widgets->run($compiled, is_string($range) && $range !== '' ? $range : null, "dashboards/{$name}")];
    }

    /** @return array<string, mixed> */
    private function admitted(string $name): array
    {
        $dashboard = $this->registry->dashboard($name);

        if (!$this->widgets->dashboardVisible($dashboard)) {
            throw new ForbiddenOperationException('This dashboard is not available to this account.');
        }

        return $dashboard;
    }
}
