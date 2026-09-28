<?php

declare(strict_types=1);

namespace Kinetis\Vector\Auth;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\RedirectResponse;
use Kinetis\Vector\Ui\VectorUi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The innermost member of the `vector-shell` group, so the group the
 * hosted shell references always exists: the application's own session
 * and authentication middleware join the same group at a higher
 * priority and register CurrentUserInterface; a request that reached
 * this one without an identity is sent to the application's login URL.
 */
#[AsMiddlewareGroup('vector-shell', priority: 0)]
final readonly class VectorShellAccessMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestScope $scope,
        private VectorUi $ui,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->scope->isRegistered(CurrentUserInterface::class)) {
            return RedirectResponse::to($this->ui->loginUrl, 302, ['Cache-Control' => 'no-store']);
        }

        return $handler->handle($request);
    }
}
