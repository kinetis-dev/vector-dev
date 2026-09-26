<?php

declare(strict_types=1);

namespace Kinetis\Vector\Http;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Hidden;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Middleware\SecurityHeadersMiddleware;
use Kinetis\Http\Responses\HtmlResponse;
use Kinetis\Http\Responses\PlainTextResponse;
use Kinetis\Http\Responses\RedirectResponse;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Store\SettingsStore;
use Kinetis\Vector\Ui\VectorUi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Hosted mode: the one HTML page that boots the admin, for the base
 * path and for every deep link under it (the `.*` constraint returns the
 * same document whatever the tail; the tail is the SPA router's, never
 * a path this code touches).
 *
 * The `vector-shell` group is the application's: its session middleware
 * joins it and registers CurrentUserInterface when a user is signed in.
 * Without one, the page redirects to the application's login URL. The
 * document is the published bundle's own index.html with, before
 * </head>, one inline script carrying the configuration the bundle
 * reads from `window.__VECTOR__` (JSON-encoded so no value can close the
 * tag) and the deployment's own head fragment. Every inline script tag
 * in the document — the bundle's, this one, the deployer's — receives
 * the response's nonce, so a strict Content-Security-Policy admits
 * exactly them.
 *
 * The nonce is the application's when its `SECURITY_CSP` carries
 * `{nonce}`: the framework's security headers put one on the request,
 * and its policy then covers this page as it covers every other. Without
 * one the page draws its own. `VECTOR_SHELL_CSP`, when set, is the
 * policy this page answers with instead, `{nonce}` replaced by that
 * same nonce; the framework leaves a policy a response already has.
 */
#[Hidden]
#[Middleware('@vector-shell')]
final readonly class ShellController
{
    public function __construct(
        private VectorUi $ui,
        private RequestScope $scope,
        private VectorRegistry $registry,
        private SettingsStore $settings,
    ) {}

    #[Get('/vector/ui')]
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->shell($request);
    }

    #[Get('/vector/ui/{path}', where: ['path' => '.*'])]
    public function deepLink(string $path, ServerRequestInterface $request): ResponseInterface
    {
        return $this->shell($request);
    }

    private function shell(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->scope->isRegistered(CurrentUserInterface::class)) {
            return RedirectResponse::to($this->ui->loginUrl, 302, ['Cache-Control' => 'no-store']);
        }

        if ($this->ui->indexHtml === null) {
            return PlainTextResponse::create(
                "The admin UI is not published. Run `vendor/bin/kinetis vector:install` to place it under {$this->ui->publishedDir}.",
                503,
                ['Cache-Control' => 'no-store'],
            );
        }

        $user = $this->scope->get(CurrentUserInterface::class);
        $name = $user instanceof VectorShellUserInterface ? $user->displayName() : null;
        $csrf = null;

        if (class_exists(\Kinetis\Session\Session::class) && $this->scope->isRegistered(\Kinetis\Session\Session::class)) {
            $session = $this->scope->get(\Kinetis\Session\Session::class);
            $csrf = $session instanceof \Kinetis\Session\Session ? $session->csrfToken() : null;
        }

        $nonce = self::nonce($request);
        $branding = isset($this->registry->settings()['branding']) ? $this->settings->values($this->registry->settingsGroup('branding')) : null;

        $config = json_encode([
            'apiBase' => '',
            'basePath' => VectorUi::PATH,
            'authMode' => 'cookie',
            'loginUrl' => $this->ui->loginUrl,
            'logoutUrl' => $this->ui->logoutUrl,
            'csrfToken' => $csrf,
            'cspNonce' => $nonce,
            'user' => $user instanceof CurrentUserInterface ? ['id' => $user->id(), 'name' => $name] : null,
            'branding' => $branding,
            'extensions' => $this->ui->extensions,
            'locale' => $this->ui->locale,
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        $attribute = htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8');
        $script = "<script nonce=\"{$attribute}\">window.__VECTOR__ = {$config};</script>";
        $head = str_replace('{nonce}', $attribute, $this->ui->shellHead);
        $html = self::nonceInlineScripts($this->ui->indexHtml, $attribute);
        $html = str_contains($html, '</head>')
            ? preg_replace('~</head>~', $script . $head . '</head>', $html, 1) ?? $script . $head . $html
            : $script . $head . $html;

        $headers = ['Cache-Control' => 'no-store'];

        if ($this->ui->csp !== null) {
            $headers['Content-Security-Policy'] = str_replace('{nonce}', $nonce, $this->ui->csp);
        }

        return HtmlResponse::create($html, 200, $headers);
    }

    /** The application's per-request CSP nonce, or a fresh one of the page's own: base64, 128 random bits or more. */
    private static function nonce(ServerRequestInterface $request): string
    {
        $nonce = $request->getAttribute(SecurityHeadersMiddleware::NONCE_ATTRIBUTE);

        return is_string($nonce) && $nonce !== '' ? $nonce : base64_encode(random_bytes(18));
    }

    /** Every `<script>` without a `src` — the bundle's own inline tags, the import map — gets the nonce. */
    private static function nonceInlineScripts(string $html, string $nonce): string
    {
        return preg_replace_callback(
            '~<script\b([^>]*)>~i',
            static fn (array $m): string => preg_match('~\bsrc\s*=~i', $m[1]) === 1 || preg_match('~\bnonce\s*=~i', $m[1]) === 1
                ? $m[0]
                : "<script nonce=\"{$nonce}\"{$m[1]}>",
            $html,
        ) ?? $html;
    }
}
