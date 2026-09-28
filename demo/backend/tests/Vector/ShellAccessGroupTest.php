<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Auth\VectorAuthMiddleware;
use App\Auth\VectorSessionMiddleware;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Kernel;
use Kinetis\Http\Middleware\Exception\UnknownMiddlewareGroupException;
use Kinetis\Http\Middleware\GlobalMiddlewareDiscovery;
use Kinetis\Http\Responses\PlainTextResponse;
use Kinetis\Http\Routing\Router;
use Kinetis\Testing\TestClient;
use Kinetis\Vector\Auth\VectorShellAccessMiddleware;
use Kinetis\Vector\Http\ShellController;
use Kinetis\Vector\Ui\VectorUi;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The hosted shell's `vector-shell` group always exists: Vector declares
 * its innermost member, the guard that sends a visitor without an
 * identity to the application's login URL, and the application's
 * session and authentication middleware join it further out. Groups
 * come from the framework's own discovery over the installed package,
 * never from a hand-written list.
 */
final class ShellAccessGroupTest extends TestCase
{
    public function testVectorDeclaresTheShellGroupsInnermostMember(): void
    {
        $attributes = array_map(
            static fn (\ReflectionAttribute $a): AsMiddlewareGroup => $a->newInstance(),
            (new \ReflectionClass(VectorShellAccessMiddleware::class))->getAttributes(AsMiddlewareGroup::class),
        );
        self::assertCount(1, $attributes);
        self::assertSame('vector-shell', $attributes[0]->name);
        self::assertSame(0, $attributes[0]->priority);

        self::assertSame(
            [VectorSessionMiddleware::class, VectorAuthMiddleware::class, VectorShellAccessMiddleware::class],
            self::discoveredGroups()['vector-shell'] ?? [],
            "the application's members run first; Vector's guard last",
        );
    }

    public function testTheShellRouteTableIsValidWithVectorsOwnMembersAlone(): void
    {
        $router = new Router();
        $router->register(ShellController::class);

        new Kernel(self::app(), $router, middlewareGroups: self::vectorGroups());
        $this->addToAssertionCount(1);

        $this->expectException(UnknownMiddlewareGroupException::class);
        new Kernel(self::app(), $router, middlewareGroups: array_diff_key(self::vectorGroups(), ['vector-shell' => true]));
    }

    public function testAVisitorWithoutAnIdentityIsSentToTheLoginUrl(): void
    {
        $client = self::client([]);

        $client->get('/probe/shell')->assertStatus(302)->assertHeader('Location', '/sign-in')->assertHeader('Cache-Control', 'no-store');
        $client->get('/probe/shell', headers: ['X-Probe-User' => ''])->assertStatus(302)->assertHeader('Location', '/sign-in');
    }

    public function testAnIdentityTheApplicationRegisteredReachesTheHandler(): void
    {
        $response = self::client([ProbeSignIn::class])->get('/probe/shell', headers: ['X-Probe-User' => '7'])->assertOk();

        self::assertSame('shell for 7', $response->body());
    }

    /** @param list<class-string> $outer the application's members, outside Vector's */
    private static function client(array $outer): TestClient
    {
        $router = new Router();
        $router->register(ShellProbe::class);
        $groups = self::vectorGroups();
        $groups['vector-shell'] = [...$outer, ...$groups['vector-shell']];

        return new TestClient(new Kernel(self::app(), $router, middlewareGroups: $groups));
    }

    private static function app(): AppScope
    {
        $app = new AppScope();
        $app->instance(VectorUi::class, new VectorUi(null, '/sign-in', '/sign-out', '/nowhere', '', null, []));
        $app->boot();

        return $app;
    }

    /** @return array<string, list<class-string>> the groups as the framework discovers them in this project */
    private static function discoveredGroups(): array
    {
        return GlobalMiddlewareDiscovery::discoverAll(new DiscoveryContext(dirname(__DIR__, 2)))['groups'];
    }

    /** @return array<string, list<class-string>> the discovered groups, with only the members Vector's package declares */
    private static function vectorGroups(): array
    {
        return array_filter(array_map(
            static fn (array $members): array => array_values(array_filter($members, static fn (string $class): bool => str_starts_with($class, 'Kinetis\\Vector\\'))),
            self::discoveredGroups(),
        ));
    }
}

/** The application's side of the group, reduced to its effect: an identity when the request names one. */
final readonly class ProbeSignIn implements MiddlewareInterface
{
    public function __construct(private RequestScope $scope) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $request->getHeaderLine('X-Probe-User');

        if ($id !== '') {
            $this->scope->instance(CurrentUserInterface::class, new ProbeUser((int) $id));
        }

        return $handler->handle($request);
    }
}

final readonly class ProbeUser implements CurrentUserInterface
{
    public function __construct(private int $id) {}

    public function id(): int
    {
        return $this->id;
    }
}

/** A page behind the shell group, answering whom it was served to. */
#[Middleware('@vector-shell')]
final readonly class ShellProbe
{
    public function __construct(private RequestScope $scope) {}

    #[Get('/probe/shell')]
    public function show(): ResponseInterface
    {
        $user = $this->scope->get(CurrentUserInterface::class);

        return PlainTextResponse::create('shell for ' . ($user instanceof CurrentUserInterface ? $user->id() : 'nobody'));
    }
}
