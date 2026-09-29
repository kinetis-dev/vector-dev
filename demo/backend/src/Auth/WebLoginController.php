<?php

declare(strict_types=1);

namespace App\Auth;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Hidden;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Middleware\SecurityHeadersMiddleware;
use Kinetis\Http\Responses\HtmlResponse;
use Kinetis\Http\Responses\RedirectResponse;
use Kinetis\Session\Middleware\SessionMiddleware;
use Kinetis\Session\Session;
use Kinetis\Vector\Ui\VectorUi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The monolith's own sign-in: a plain HTML form, the session cookie, and
 * a redirect into the hosted admin. What a Twig or Latte application
 * would render from a template, rendered here inline to keep the demo
 * to one file. Its inline style carries the request's CSP nonce, which
 * the application's SECURITY_CSP asks for. A form left open until its
 * session expired comes back here with a notice, not the CSRF check's
 * JSON (WebCsrfMiddleware).
 */
#[Hidden]
#[Middleware(SessionMiddleware::class)]
#[Middleware(WebCsrfMiddleware::class)]
final readonly class WebLoginController
{
    public function __construct(
        private Session $session,
        private Credentials $credentials,
        private LoginAttempts $attempts,
    ) {}

    #[Get('/login')]
    public function form(ServerRequestInterface $request): ResponseInterface
    {
        if (is_int($this->session->get('userId'))) {
            return RedirectResponse::to(VectorUi::PATH, 303);
        }

        $notice = $this->session->flashed(WebCsrfMiddleware::NOTICE);
        $email = $this->session->flashed(WebCsrfMiddleware::EMAIL);

        return $this->page($request, null, notice: is_string($notice) ? $notice : null, email: is_string($email) ? $email : '');
    }

    #[Post('/login')]
    public function attempt(#[Body] LoginRequest $data, ServerRequestInterface $request): ResponseInterface
    {
        $address = LoginController::address($request);

        try {
            $wait = $this->attempts->lockedFor($data->email, $address);

            if ($wait > 0) {
                return $this->page($request, sprintf('Too many failed sign-ins. Try again in %d minutes.', (int) ceil($wait / 60)), 429, ['Retry-After' => (string) $wait]);
            }

            $user = $this->credentials->verify($data->email, $data->password);

            if ($user === null) {
                $this->attempts->failed($data->email, $address);

                return $this->page($request, 'Invalid credentials.', email: $data->email);
            }

            $this->attempts->succeeded($data->email, $address);
        } catch (LoginUnavailableException $e) {
            return $this->page($request, $e->getMessage(), 503);
        }

        $this->session->regenerate();
        $this->session->set('userId', $user->id());
        $this->session->set('userName', $user->name());
        $this->session->set('isAdmin', $user->isAdmin());

        return RedirectResponse::to(VectorUi::PATH, 303);
    }

    #[Post('/logout')]
    public function logout(): ResponseInterface
    {
        $this->session->destroy();

        return RedirectResponse::to('/login', 303);
    }

    /** @param array<string, string> $headers */
    private function page(ServerRequestInterface $request, ?string $error, int $status = 401, array $headers = [], ?string $notice = null, string $email = ''): ResponseInterface
    {
        $nonce = $request->getAttribute(SecurityHeadersMiddleware::NONCE_ATTRIBUTE);
        $nonceAttribute = is_string($nonce) ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';
        $token = htmlspecialchars($this->session->csrfToken(), ENT_QUOTES, 'UTF-8');
        $alert = $error === null ? '' : '<p class="error" role="alert">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>';
        $info = $notice === null ? '' : '<p class="notice" role="status">' . htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') . '</p>';
        $emailValue = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

        return HtmlResponse::create(<<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Sign in · Vector Demo</title>
                <style{$nonceAttribute}>
                    body { font-family: system-ui, sans-serif; background: #f4f6f8; display: grid; place-items: center; min-height: 100vh; margin: 0; }
                    form { background: #fff; padding: 2rem; border-radius: 12px; width: min(22rem, 90vw); box-shadow: 0 8px 24px rgba(0,0,0,.08); }
                    label { display: block; margin: .75rem 0 .25rem; font-size: .9rem; }
                    input { width: 100%; padding: .6rem; border: 1px solid #cfd8dc; border-radius: 8px; box-sizing: border-box; }
                    button { margin-top: 1.25rem; width: 100%; padding: .7rem; border: 0; border-radius: 8px; background: #1F5F8B; color: #fff; font-size: 1rem; }
                    .error { color: #c62828; margin: .5rem 0 0; }
                    .notice { background: #e3f2fd; color: #0d47a1; border-radius: 8px; padding: .6rem .75rem; margin: .5rem 0 0; font-size: .9rem; }
                </style>
            </head>
            <body>
                <form method="post" action="/login">
                    <h1>Vector Demo</h1>
                    <p>Sign in to the admin (hosted mode)</p>
                    {$info}
                    <input type="hidden" name="_token" value="{$token}">
                    <label for="email">E-mail</label>
                    <input id="email" name="email" type="email" autocomplete="username" value="{$emailValue}" required>
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                    {$alert}
                    <button type="submit">Sign in</button>
                </form>
            </body>
            </html>
            HTML, $error === null ? 200 : $status, ['Cache-Control' => 'no-store', ...$headers]);
    }
}
