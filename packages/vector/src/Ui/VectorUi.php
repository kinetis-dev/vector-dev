<?php

declare(strict_types=1);

namespace Kinetis\Vector\Ui;

use Kinetis\Config\Config;

/**
 * The hosted admin UI as the worker knows it: the published shell
 * document, read once at boot, where the application's login and
 * logout live, and the deployment's own additions to the document — a
 * raw `<head>` fragment and a Content-Security-Policy template. Both
 * are deployment configuration, never data an admin edits (§4.15).
 * Immutable and request-neutral, so one instance serves every request;
 * the per-request pieces are injected by ShellController.
 */
final readonly class VectorUi
{
    public const string PATH = '/vector/ui';

    public function __construct(
        public ?string $indexHtml,
        public string $loginUrl,
        public string $logoutUrl,
        public string $publishedDir,
        public string $shellHead,
        public ?string $csp,
        /** @var list<string> */
        public array $extensions,
        /** The UI's language, a BCP 47 tag such as de or pt-BR; null leaves it to the bundle. */
        public ?string $locale = null,
    ) {}

    /**
     * Reads the published `public/vector/ui/index.html` under the
     * project root the framework bound, or `VECTOR_UI_DIR` when set. A
     * missing document is not an error at boot: the shell answers 503
     * until `vector:install` publishes one.
     *
     * `VECTOR_SHELL_HEAD` is appended verbatim before `</head>` — the
     * deployer's script or link tags, which carry their own nonce
     * through the `{nonce}` placeholder. `VECTOR_SHELL_CSP` is the
     * policy the shell page itself answers with, `{nonce}` replaced per
     * response; unset, the page carries whatever the application's
     * security headers send. `VECTOR_UI_EXTENSIONS` lists ES-module URLs
     * (comma-separated, same-origin or CORS-enabled) the bundle imports
     * after boot: runtime extensions of §4.15, the deployer's second way
     * to add script.
     */
    public static function fromConfig(Config $config, string $projectRoot): self
    {
        $dir = $config->get('VECTOR_UI_DIR') ?? $projectRoot . '/public' . self::PATH;
        $index = $dir . '/index.html';
        $html = is_file($index) ? file_get_contents($index) : false;
        $csp = trim($config->string('VECTOR_SHELL_CSP', ''));

        return new self(
            $html === false ? null : $html,
            $config->get('VECTOR_LOGIN_URL') ?? '/login',
            $config->get('VECTOR_LOGOUT_URL') ?? '/logout',
            $dir,
            $config->string('VECTOR_SHELL_HEAD', ''),
            $csp === '' ? null : $csp,
            array_values(array_filter(array_map('trim', explode(',', $config->string('VECTOR_UI_EXTENSIONS', ''))))),
            self::locale($config->string('VECTOR_UI_LOCALE', '')),
        );
    }

    /** A BCP 47 language tag (letters, digits, hyphens), or null when unset. */
    private static function locale(string $locale): ?string
    {
        $locale = trim($locale);

        if ($locale === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/D', $locale) !== 1) {
            throw new \InvalidArgumentException("VECTOR_UI_LOCALE \"{$locale}\" is not a language tag such as en, de or pt-BR.");
        }

        return $locale;
    }
}
