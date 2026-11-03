<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Cache\DiscoveryContext;
use Kinetis\Http\Middleware\GlobalMiddlewareDiscovery;
use Kinetis\Http\Routing\Route;
use Kinetis\Http\Routing\RouteDiscovery;
use Kinetis\Vector\Http\ExtensionModuleController;
use Kinetis\Vector\Http\ResourceController;
use Kinetis\Vector\Http\ShellController;
use PHPUnit\Framework\TestCase;

/**
 * `/vector/extensions` shares no path with another route of the project:
 * resources live under `/vector/r/{resource}`, so a resource slugged
 * `extensions` is `/vector/r/extensions`, and no `/vector/{resource}`
 * route exists. The module route stays out of `/vector/ui`, every path
 * of which is the shell's deep link.
 */
final class ExtensionModuleRouteTableTest extends TestCase
{
    /** @return list<string> the controllers of the GET routes matching $path, in the project's discovered table */
    private static function matching(string $path): array
    {
        $context = new DiscoveryContext(dirname(__DIR__, 2));
        $router = RouteDiscovery::discover($context, globalMiddleware: GlobalMiddlewareDiscovery::discoverAll($context)['global']);

        return array_values(array_unique(array_map(
            static fn (Route $route): string => $route->controllerClass,
            array_filter($router->routes(), static fn (Route $route): bool => $route->httpMethod === 'GET' && $route->matchPath($path) !== null),
        )));
    }

    public function testAModulePathMatchesTheModuleRouteAlone(): void
    {
        self::assertSame([ExtensionModuleController::class], self::matching('/vector/extensions/acme/ui-extension/0123456789abcdef/queues.js'));
    }

    public function testAResourceSluggedExtensionsIsUnderTheResourcePrefix(): void
    {
        self::assertSame([ResourceController::class], self::matching('/vector/r/extensions'));
        self::assertSame([ResourceController::class], self::matching('/vector/r/extensions/7'));
        self::assertSame([], self::matching('/vector/extensions'), 'no /vector/{resource} route takes the bare prefix');
    }

    public function testTheShellsPathsAreNotTheModuleRoutes(): void
    {
        self::assertSame([ShellController::class], self::matching('/vector/ui/vector/extensions/acme/ui-extension/0123456789abcdef/queues.js'));
        self::assertSame([ShellController::class], self::matching('/vector/ui/extensions'));
    }
}
