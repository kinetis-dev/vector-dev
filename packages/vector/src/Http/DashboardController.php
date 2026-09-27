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
        private \Kinetis\Http\CurrentUserInterface $user,
        private \Kinetis\Vector\Translation\LocaleResolver $locales,
        private \Kinetis\Vector\Translation\Labels $labels,
    ) {}

    /** @return array{data: array<string, mixed>} */
    #[Get('/dashboards/{name}')]
    public function show(string $name): array
    {
        $dashboard = $this->admitted($name);

        return ['data' => $this->labels->document(['name' => $name, 'label' => $dashboard['label'], 'icon' => $dashboard['icon'], 'widgets' => $this->widgets->visible($dashboard['widgets'])], $this->locale())];
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

        // a result's slice, column and caption labels; its rows and points are data
        return ['data' => $this->labels->document($this->widgets->run($compiled, is_string($range) && $range !== '' ? $range : null, "dashboards/{$name}"), $this->locale())];
    }

    private function locale(): string
    {
        return $this->locales->resolve((string) $this->user->id())['locale'];
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
