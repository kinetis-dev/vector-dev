<?php

declare(strict_types=1);

namespace App\Auth;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Responses\RedirectResponse;
use Kinetis\Session\Middleware\CsrfMiddleware;
use Kinetis\Session\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The framework's CSRF check for the demo's own HTML pages, with an
 * answer a person can read. A sign-in page left open longer than the
 * session lives posts a token its new session never issued, and
 * CsrfMiddleware refuses that with a JSON 403 — right for an API, a dead
 * end in a browser. This runs that same check, unchanged; when it
 * refuses before the page's handler ran, the browser is sent back to the
 * sign-in form (303) with a notice and the e-mail it typed, and the form
 * renders with a fresh token. The refused request still changes nothing
 * the form would have changed.
 */
final readonly class WebCsrfMiddleware implements MiddlewareInterface
{
    public const string NOTICE = 'loginNotice';
    public const string EMAIL = 'loginEmail';

    public function __construct(
        private CsrfMiddleware $csrf,
        private RequestScope $scope,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $probe = new ReachedHandler($handler);
        $response = $this->csrf->process($request, $probe);

        if ($probe->reached || $response->getStatusCode() !== 403 || !$this->scope->isRegistered(Session::class)) {
            return $response; // passed, or refused for another reason (a misordered route answers 500 as before)
        }

        $session = $this->scope->get(Session::class);

        if ($session instanceof Session) {
            $session->flash(self::NOTICE, 'This page was open too long and its session expired. Please sign in again.');
            $body = $request->getParsedBody();
            $email = is_array($body) ? $body['email'] ?? null : null;

            if (is_string($email) && $email !== '' && strlen($email) <= 254) {
                $session->flash(self::EMAIL, $email); // never the password
            }
        }

        return RedirectResponse::to('/login', 303, ['Cache-Control' => 'no-store']);
    }
}
