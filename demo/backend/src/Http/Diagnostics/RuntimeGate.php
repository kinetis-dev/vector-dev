<?php

declare(strict_types=1);

namespace App\Http\Diagnostics;

use Kinetis\Http\Attributes\AsGlobalMiddleware;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Http\Routing\Route;
use Kinetis\Runtime\AppEnvironment;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Closes GET /_demo/runtime outside APP_ENV=development, for every HTTP
 * method. The route itself is GET only, so the Router alone would answer
 * any other method on its path with 405 and `Allow: GET`, confirming the
 * route exists. This answers before routing instead, with the Kernel's own
 * 404 for an unmatched path, so no method can tell the path from one that
 * was never registered.
 *
 * Priority 0, the lowest: discovered global middleware runs after the
 * framework's own and the application's explicit registrations, so
 * everything an unmatched path passes through, this path passes through
 * too. The path is compared the way the Router compares it
 * (Route::normalizePath()). In development it passes every request on.
 */
#[AsGlobalMiddleware(priority: 0)]
final readonly class RuntimeGate implements MiddlewareInterface
{
    public const string PATH = '/_demo/runtime';

    public function __construct(private AppEnvironment $environment) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if ($this->environment !== AppEnvironment::Development && Route::normalizePath($path) === self::PATH) {
            return self::notFound($path);
        }

        return $handler->handle($request);
    }

    /** Byte for byte the Kernel's answer for a path no route matches. */
    public static function notFound(string $path): ResponseInterface
    {
        return ErrorResponse::create(404, sprintf('No route matches path "%s".', $path));
    }
}
