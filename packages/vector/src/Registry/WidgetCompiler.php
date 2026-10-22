<?php

declare(strict_types=1);

namespace Kinetis\Vector\Registry;

use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Widgets\VectorDashboard;
use Kinetis\Vector\Widgets\VectorWidget;
use Kinetis\Vector\Widgets\WidgetRequest;

/** Compiles widget and dashboard definitions into descriptor data. */
final class WidgetCompiler
{
    /**
     * @param list<class-string<VectorWidget>> $classes
     * @return list<array<string, mixed>>
     */
    public static function widgets(array $classes, string $owner): array
    {
        $widgets = [];

        foreach ($classes as $class) {
            if (!is_subclass_of($class, VectorWidget::class)) {
                throw ResourceCompileException::resource($owner, "widgets() names {$class}, which is not a VectorWidget");
            }

            $widget = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $name = $widget->name();

            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) !== 1) {
                throw ResourceCompileException::resource($owner, "widget {$class} has the name \"{$name}\"; a widget name is lower-case kebab-case");
            }

            if (isset($widgets[$name])) {
                throw ResourceCompileException::resource($owner, "two widgets are named \"{$name}\"");
            }

            if (!in_array($widget->type(), [VectorWidget::STAT, VectorWidget::TREND, VectorWidget::PARTITION, VectorWidget::PROGRESS, VectorWidget::TABLE], true)) {
                throw ResourceCompileException::resource($owner, "widget {$class} has the type \"{$widget->type()}\"");
            }

            self::checkRanges($widget, $class, $owner);

            $widgets[$name] = [
                'name' => $name,
                'class' => $class,
                'label' => $widget->label(),
                'type' => $widget->type(),
                'icon' => $widget->icon(),
                'ranges' => $widget->ranges(),
                'width' => in_array($widget->width(), [3, 4, 6, 12], true) ? $widget->width() : 4,
                'cacheSeconds' => max(0, $widget->cacheSeconds()),
                'cacheShared' => $widget->cacheShared(),
            ];
        }

        return array_values($widgets);
    }

    /** Every range a widget declares is one WidgetRequest can resolve. */
    private static function checkRanges(VectorWidget $widget, string $class, string $owner): void
    {
        foreach (array_keys($widget->ranges()) as $range) {
            if (!WidgetRequest::isRange($range)) {
                throw ResourceCompileException::resource($owner, "widget {$class} declares the range \"{$range}\"; a range is a number of hours, days, months or years (24h, 30d, 6m, 1y) or all");
            }
        }
    }

    /**
     * @param list<class-string<VectorDashboard>> $classes
     * @return array<string, array<string, mixed>> name => dashboard, `main` first, then by name
     */
    public static function dashboards(array $classes): array
    {
        $dashboards = [];

        foreach ($classes as $class) {
            $dashboard = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $name = $dashboard->name();

            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) !== 1) {
                throw ResourceCompileException::resource($class, "has the dashboard name \"{$name}\"; a dashboard name is lower-case kebab-case");
            }

            if (isset($dashboards[$name])) {
                throw ResourceCompileException::resource($class, "and {$dashboards[$name]['class']} are both named \"{$name}\"");
            }

            $dashboards[$name] = [
                'name' => $name,
                'class' => $class,
                'label' => $dashboard->label(),
                'icon' => $dashboard->icon(),
                'widgets' => self::widgets($dashboard->widgets(), $class),
            ];
        }

        uksort($dashboards, static function (string $a, string $b): int {
            if (($a === 'main') !== ($b === 'main')) {
                return $a === 'main' ? -1 : 1;
            }

            return strcmp($a, $b);
        });

        return $dashboards;
    }
}
