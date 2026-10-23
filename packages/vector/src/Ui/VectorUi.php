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
 *
 * It also holds the two UI versions, read once at boot without hashing:
 * the @kinetis/vector-ui version this kinetis/vector pins
 * (`resources/ui.json`), which the descriptor publishes so the dashboard
 * can warn about drift, and the version the served directory's
 * `vector-bundle.json` names. Either is null when its file is missing or
 * malformed; neither stops the worker from booting.
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
        public ?string $expectedUiVersion = null,
        public ?string $installedUiVersion = null,
    ) {}

    /**
     * The served directory: `VECTOR_UI_DIR` when set, else
     * `public/vector/ui` under the project root. `vector:install-ui`
     * installs into it.
     */
    public static function directory(Config $config, string $projectRoot): string
    {
        return $config->get('VECTOR_UI_DIR') ?? $projectRoot . '/public' . self::PATH;
    }

    /**
     * Reads the published `public/vector/ui/index.html` under the
     * project root the framework bound, or `VECTOR_UI_DIR` when set. A
     * missing document is not an error at boot: the shell answers 503
     * until `vector:install-ui` installs one. $pinFile is the UI pin,
     * this package's own unless a test names another.
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
    public static function fromConfig(Config $config, string $projectRoot, ?string $pinFile = null): self
    {
        $dir = self::directory($config, $projectRoot);
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
            UiPin::tryFromFile($pinFile ?? UiPin::path())?->version,
            HostedBundle::read($dir . '/' . HostedBundle::MANIFEST)?->version,
        );
    }
}
