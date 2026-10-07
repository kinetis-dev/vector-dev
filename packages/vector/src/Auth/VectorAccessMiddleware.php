<?php

declare(strict_types=1);

namespace Kinetis\Vector\Auth;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The innermost member of the `vector` group: the application's own
 * authentication middleware joins the same group at a higher priority
 * and registers CurrentUserInterface; this one refuses a request that
 * reached it without an identity, or with one the application's
 * VectorAccessInterface does not admit.
 */
#[AsMiddlewareGroup('vector', priority: 0)]
final readonly class VectorAccessMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestScope $scope,
        private VectorAccessInterface $access,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // every answer of the admin API — a refusal included — is about one account; a shared browser or proxy keeps none of it
        return self::noStore($this->answer($request, $handler));
    }

    private function answer(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->scope->isRegistered(CurrentUserInterface::class)) {
            return ErrorResponse::create(401, 'Unauthenticated.');
        }

        $user = $this->scope->get(CurrentUserInterface::class);

        if (!$user instanceof CurrentUserInterface) {
            return ErrorResponse::create(401, 'Unauthenticated.');
        }

        if (!$this->access->canAccessVector($user) && !self::isStoppingImpersonation($request, $user)) {
            return ErrorResponse::create(403, 'This account has no access to the admin.');
        }

        return $handler->handle($request);
    }

    private static function noStore(ResponseInterface $response): ResponseInterface
    {
        return $response->hasHeader('Cache-Control') ? $response : $response->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The one request an unadmitted identity may make: ending an
     * impersonation. An admin acting as a user without admin access is
     * refused everywhere else, and must still be able to become
     * themselves again.
     */
    private static function isStoppingImpersonation(ServerRequestInterface $request, CurrentUserInterface $user): bool
    {
        return $user instanceof ImpersonatedUserInterface
            && $user->impersonatorId() !== null
            && strtoupper($request->getMethod()) === 'DELETE'
            && rtrim($request->getUri()->getPath(), '/') === '/vector/impersonate';
    }
}
