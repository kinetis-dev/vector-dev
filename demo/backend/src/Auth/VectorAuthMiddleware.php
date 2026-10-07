<?php

declare(strict_types=1);

namespace App\Auth;

use Kinetis\AuthJwt\JwtAuthenticator;
use Kinetis\AuthJwt\JwtUser;
use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Http\Auth\AuthorizationToken68Parser;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Session\Session;
use Kinetis\Vector\Auth\ImpersonationInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The demo admits both Vector deployment modes on one API: a bearer JWT
 * (standalone UI, API clients, MCP) or the browser session the hosted
 * shell uses. A session-authenticated unsafe request must carry the CSRF
 * token the shell was given; a bearer request needs none, since a
 * browser never attaches a bearer header on its own.
 *
 * Registers the identity on the request scope as a DemoActor — the one
 * object Vector's policies, shell and log read — and the impersonation
 * service that knows how this mode swaps identities. Registers nothing
 * when neither credential is present, so the shell redirects to login
 * and the API answers 401.
 */
#[AsMiddlewareGroup('vector', priority: 50)]
#[AsMiddlewareGroup('vector-shell', priority: 50)]
#[AsMiddlewareGroup('mcp', priority: 50)]
final readonly class VectorAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestScope $scope,
        private JwtAuthenticator $jwt,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = AuthorizationToken68Parser::parse($request, 'Bearer');

        if ($token !== null) {
            $user = $this->jwt->authenticate($token);

            if ($user === null) {
                return ErrorResponse::create(401, 'Unauthenticated.', ['WWW-Authenticate' => 'Bearer']);
            }

            $impersonator = $user->claim('impersonator');
            $actor = new DemoActor(
                (int) $user->id(),
                is_string($user->claim('name')) ? $user->claim('name') : 'Account',
                $user->claim('vector') === true,
                is_int($impersonator) ? $impersonator : null,
                is_string($user->claim('impersonatorName')) ? $user->claim('impersonatorName') : null,
            );
            $this->scope->instance(CurrentUserInterface::class, $actor);
            $this->scope->instance(JwtUser::class, $user);
            $this->scope->bind(ImpersonationInterface::class, DemoImpersonation::class);

            return $handler->handle($request);
        }

        $session = $this->scope->isRegistered(Session::class) ? $this->scope->get(Session::class) : null;

        if ($session instanceof Session && is_int($session->get('userId'))) {
            $method = strtoupper($request->getMethod());

            if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && !$session->verifyCsrfToken($request->getHeaderLine('X-CSRF-Token'))) {
                return ErrorResponse::create(403, 'CSRF token mismatch.');
            }

            $impersonator = $session->get('impersonator');
            $this->scope->instance(CurrentUserInterface::class, new DemoActor(
                $session->get('userId'),
                (string) $session->get('userName', ''),
                $session->get('isAdmin') === true,
                is_int($impersonator) ? $impersonator : null,
                is_string($session->get('impersonatorName')) ? $session->get('impersonatorName') : null,
            ));
            $this->scope->bind(ImpersonationInterface::class, DemoImpersonation::class);
        }

        return $handler->handle($request);
    }
}
