<?php

declare(strict_types=1);

namespace Kinetis\Vector\Http;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Vector\Query\ResourceService;
use Psr\Http\Message\ServerRequestInterface;

/** Global search, and the menu's badges, across every resource the caller may list. */
#[RoutePrefix('/vector')]
#[Middleware('@vector')]
final readonly class SearchController
{
    public function __construct(private ResourceService $service) {}

    /** @return array{results: list<array{resource: string, resourceLabel: string, id: int|string, title: string, subtitle: string|null, image: string|null}>} */
    #[Get('/search')]
    public function search(ServerRequestInterface $request): array
    {
        $term = $request->getQueryParams()['q'] ?? '';

        return ['results' => $this->service->search(is_string($term) ? $term : '')];
    }

    /**
     * The menu's badges: for each resource with one that this user may
     * list, the count of its filter. Cheap enough to poll.
     *
     * @return array{data: array<string, array{count: int, color: string, label: string|null}>}
     */
    #[Get('/badges')]
    public function badges(): array
    {
        return ['data' => $this->service->badges()];
    }
}
