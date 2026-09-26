<?php

declare(strict_types=1);

namespace Kinetis\Vector\Widgets;

use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Exception\ForbiddenOperationException;
use Kinetis\Vector\Exception\InvalidListRequestException;
use Kinetis\Vector\Exception\ResourceNotFoundException;

/** Computes one compiled widget for a range, through the per-worker cache when the widget allows it. */
final readonly class WidgetRunner
{
    public function __construct(
        private RequestScope $scope,
        private CurrentUserInterface $user,
        private WidgetCache $cache,
    ) {}

    /**
     * @param array<string, mixed> $compiled the widget as the registry compiled it
     * @return array<string, mixed>
     */
    public function run(array $compiled, ?string $range, string $cacheScope): array
    {
        $ranges = $compiled['ranges'];
        $range ??= array_key_first($ranges) ?? 'all';

        if ($ranges !== [] && !isset($ranges[$range])) {
            throw new InvalidListRequestException("\"{$range}\" is not a range of {$compiled['name']}; use " . implode(', ', array_keys($ranges)) . '.');
        }

        $widget = $this->scope->get($compiled['class']);

        if (!$widget instanceof VectorWidget) {
            throw new \LogicException("{$compiled['class']} is not a VectorWidget.");
        }

        if (!$widget->canView($this->user)) {
            throw new ForbiddenOperationException("The widget {$compiled['name']} is not available to this account.");
        }

        $key = "{$cacheScope}/{$compiled['name']}/{$range}";
        $seconds = $compiled['cacheSeconds'];

        if ($seconds > 0 && ($cached = $this->cache->get($key)) !== null) {
            return [...$cached, 'cached' => true];
        }

        $result = [...$widget->calculate(WidgetRequest::for($ranges === [] ? 'all' : $range, $this->user))->toArray(), 'range' => $range, 'cached' => false];

        if ($seconds > 0) {
            $this->cache->put($key, $result, $seconds);
        }

        return $result;
    }

    /**
     * The widgets of a compiled list this user may view, as the client renders their cards.
     *
     * @param list<array<string, mixed>> $widgets
     * @return list<array<string, mixed>>
     */
    public function visible(array $widgets): array
    {
        $visible = [];

        foreach ($widgets as $widget) {
            $instance = $this->scope->get($widget['class']);

            if ($instance instanceof VectorWidget && $instance->canView($this->user)) {
                unset($widget['class']);
                $visible[] = $widget;
            }
        }

        return $visible;
    }

    public static function notFound(string $name): ResourceNotFoundException
    {
        return ResourceNotFoundException::slug("widgets/{$name}");
    }
}
