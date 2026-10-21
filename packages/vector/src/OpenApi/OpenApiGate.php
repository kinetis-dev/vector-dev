<?php

declare(strict_types=1);

namespace Kinetis\Vector\OpenApi;

use Kinetis\Config\Config;
use Kinetis\Http\Attributes\AsGlobalMiddleware;
use Kinetis\Http\OpenApi\DocumentationController;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Http\Routing\Exception\RouteNotFoundException;
use Kinetis\Http\Routing\Route;
use Kinetis\Http\Routing\Router;
use Kinetis\OpenApi\OpenApiAccess;
use Kinetis\Vector\Http\OpenApiController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves `GET /vector/openapi.json` exactly where the framework serves
 * its own `/openapi.json`: OPENAPI_ENVIRONMENTS naming APP_ENV, as
 * {@see OpenApiAccess} decides it. The framework closes its pair by
 * leaving them out of the route table it hands the Kernel, a step no
 * package can reach; this is the closest equivalent. Where the document
 * is closed, every request for its path, whatever the method, gets the
 * Kernel's own 404 for a path no route matches, before routing and
 * before the `vector` group, so nothing tells it from a path that was
 * never registered.
 *
 * The Kernel builds global middleware once, from the booted Config, so
 * this is decided from the same Config and at the same point as the
 * framework's own table. A changed setting takes effect when the worker
 * restarts. Priority 0, the lowest: every other global middleware wraps
 * this path as it wraps an unmatched one. A request is closed when the
 * controller's own route matches its path, asked through
 * {@see Route::matchPath()} exactly as {@see Router::match()} asks it, so
 * every spelling the Router would route here is closed and no other.
 */
#[AsGlobalMiddleware(priority: 0)]
final readonly class OpenApiGate implements MiddlewareInterface
{
    private bool $open;

    /** @var list<Route> the controller's routes, as the Router compiles them */
    private array $routes;

    public function __construct(Config $config)
    {
        $this->open = self::opens($config);
        $routes = new Router();
        $routes->register(OpenApiController::class);
        $this->routes = $routes->routes();
    }

    /**
     * Whether the framework serves its own documentation under $config.
     * {@see OpenApiAccess} keeps its decision to itself and acts on a
     * route table, so it is asked about one holding only its controller.
     */
    public static function opens(Config $config): bool
    {
        $probe = new Router();
        $probe->register(DocumentationController::class);

        return OpenApiAccess::fromConfig($config)->routes($probe)->routes() !== [];
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->open) {
            return $handler->handle($request);
        }

        // the path the Kernel hands the Router, and the Router's own test of it
        $path = $request->getUri()->getPath();

        foreach ($this->routes as $route) {
            if ($route->matchPath($path) !== null) {
                return ErrorResponse::create(404, RouteNotFoundException::forPath($path)->getMessage());
            }
        }

        return $handler->handle($request);
    }
}
