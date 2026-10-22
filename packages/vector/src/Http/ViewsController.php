<?php

declare(strict_types=1);

namespace Kinetis\Vector\Http;

use Kinetis\Http\Attributes\Delete;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Attributes\Put;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\JsonResponse;
use Kinetis\Http\Responses\PlainTextResponse;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Exception\ForbiddenOperationException;
use Kinetis\Vector\Exception\RecordNotFoundException;
use Kinetis\Vector\Query\ListRequest;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Store\ViewStore;
use Kinetis\Vector\Write\ValueConverter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Saved views of a resource's list. A view's state is validated through
 * the list grammar before it is stored, so a saved view can never carry
 * a filter the resource would refuse.
 */
#[RoutePrefix('/vector/views')]
#[Middleware('@vector')]
final readonly class ViewsController
{
    public function __construct(
        private ViewStore $views,
        private ResourceService $service,
        private ValueConverter $converter,
        private CurrentUserInterface $user,
    ) {}

    /** @return array{data: list<array<string, mixed>>} */
    #[Get('/{resource}')]
    public function index(string $resource): array
    {
        $descriptor = $this->service->resource($resource);
        $this->service->authorizeViewAny($descriptor);

        return ['data' => $this->views->forResource($descriptor->slug(), (string) $this->user->id())];
    }

    #[Post('/{resource}', status: 201)]
    public function create(string $resource, ServerRequestInterface $request): ResponseInterface
    {
        $descriptor = $this->service->resource($resource);
        $this->service->authorizeViewAny($descriptor);
        [$name, $shared, $isDefault, $state, $resourceDefault] = $this->input($descriptor->slug(), $this->body($request));
        $id = $this->views->create($descriptor->slug(), (string) $this->user->id(), $name, $shared, $isDefault, $state, $resourceDefault);

        return JsonResponse::create(['data' => $this->views->find($id, (string) $this->user->id())], 201);
    }

    /** @return array{data: array<string, mixed>|null} */
    #[Put('/{resource}/{id}')]
    public function update(string $resource, string $id, ServerRequestInterface $request): array
    {
        $descriptor = $this->service->resource($resource);
        $view = $this->owned($descriptor->slug(), $id);
        [$name, $shared, $isDefault, $state, $resourceDefault] = $this->input($descriptor->slug(), $this->body($request));
        $this->views->update($view['id'], $descriptor->slug(), (string) $this->user->id(), $name, $shared, $isDefault, $state, $resourceDefault);

        return ['data' => $this->views->find($view['id'], (string) $this->user->id())];
    }

    #[Delete('/{resource}/{id}', status: 204)]
    public function delete(string $resource, string $id): ResponseInterface
    {
        $descriptor = $this->service->resource($resource);
        $this->views->delete($this->owned($descriptor->slug(), $id)['id']);

        return PlainTextResponse::create('', 204);
    }

    /** @return array{id: int, resource: string, ownerId: string, name: string, shared: bool, isDefault: bool, resourceDefault: bool, state: array<string, mixed>, mine: bool} */
    private function owned(string $resource, string $id): array
    {
        $view = preg_match('/^[1-9]\d{0,17}$/D', $id) === 1 ? $this->views->find((int) $id, (string) $this->user->id()) : null;

        if ($view === null || $view['resource'] !== $resource) {
            throw RecordNotFoundException::of('views', $id);
        }

        if (!$view['mine']) {
            throw new ForbiddenOperationException('Only the owner of a view changes it.');
        }

        return $view;
    }

    /**
     * `resourceDefault` makes a shared view everyone's default for the
     * resource. Setting it, or saving a view that keeps it, is the
     * policy's setDefaultView() to decide; dropping it never is.
     *
     * @param array<array-key, mixed> $body
     * @return array{0: string, 1: bool, 2: bool, 3: array<string, mixed>, 4: bool}
     */
    private function input(string $resource, array $body): array
    {
        $violations = [];
        $name = $body['name'] ?? null;

        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 80) {
            $violations[] = new Violation(['name'], 'required', 'must be 1 to 80 characters.');
        }

        foreach (['shared', 'isDefault', 'resourceDefault'] as $flag) {
            if (isset($body[$flag]) && !is_bool($body[$flag])) {
                $violations[] = new Violation([$flag], 'type_mismatch', 'must be true or false.');
            }
        }

        $resourceDefault = ($body['resourceDefault'] ?? false) === true;

        if ($resourceDefault && ($body['shared'] ?? false) !== true) {
            $violations[] = new Violation(['resourceDefault'], 'requires_shared', 'needs the view to be shared with every admin.');
        }

        $state = $body['state'] ?? [];

        if (!is_array($state) || array_is_list($state) && $state !== []) {
            $violations[] = new Violation(['state'], 'type_mismatch', 'must be an object.');
            $state = [];
        }

        if (strlen(json_encode($state, JSON_THROW_ON_ERROR)) > ViewStore::MAX_STATE_BYTES) {
            $violations[] = new Violation(['state'], 'too_large', 'must be at most 16 KiB.');
        }

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        // the state must be a list request the resource accepts today
        $descriptor = $this->service->resource($resource);

        if ($resourceDefault) {
            $this->service->authorizeDefaultView($descriptor);
        }

        ListRequest::fromQueryParams(self::listParams($state), $descriptor, $this->converter);
        $state = array_intersect_key($state, array_flip(['filter', 'q', 'sort', 'group', 'columns', 'perPage', 'summaries']));

        /** @var string $name */
        return [trim($name), ($body['shared'] ?? false) === true, ($body['isDefault'] ?? false) === true, $state, $resourceDefault];
    }

    /**
     * The list request a view's state holds: its non-empty list parameters.
     *
     * @param array<array-key, mixed> $state
     * @return array<string, mixed>
     */
    private static function listParams(array $state): array
    {
        $params = [];

        foreach (['filter', 'q', 'sort', 'group', 'columns', 'perPage'] as $key) {
            if (isset($state[$key]) && $state[$key] !== '' && $state[$key] !== []) {
                $params[$key] = $state[$key];
            }
        }

        return $params;
    }

    /** @return array<array-key, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();

        try {
            $decoded = trim($raw) === '' ? [] : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::fromViolations([new Violation([], 'invalid_json', 'The request body is not valid JSON.')]);
        }

        return is_array($decoded) ? $decoded : throw ValidationException::fromViolations([new Violation([], 'type_mismatch', 'The request body must be a JSON object.')]);
    }
}
