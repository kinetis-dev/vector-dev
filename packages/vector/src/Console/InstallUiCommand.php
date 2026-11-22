<?php

declare(strict_types=1);

namespace Kinetis\Vector\Console;

use Kinetis\Config\Config;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Vector\Ui\TarballInstaller;
use Kinetis\Vector\Ui\UiPin;
use Kinetis\Vector\Ui\UiTarballSource;

/**
 * Installs the hosted admin UI — the bundle @kinetis/vector-ui ships in
 * `dist/static` — into the served directory: `VECTOR_UI_DIR`, else
 * `public/vector/ui`, through TarballInstaller, which documents the
 * options, the checks and the exit codes. No bootstrap: this touches
 * files and the registry only.
 *
 *     kinetis vector:install-ui                       the version resources/ui.json pins
 *     kinetis vector:install-ui --version=X.Y.Z       another published version
 *     kinetis vector:install-ui --from=<tgz|dir> [--integrity=sha512-…] [--version=X.Y.Z]
 *     kinetis vector:install-ui --check [--version=X.Y.Z]
 *     kinetis vector:install-ui --force               reinstall what is installed already
 *
 * `--check` also passes on a served bundle of another package that
 * includes the pinned @kinetis/vector-ui. The registry source is
 * injectable for testability.
 */
final readonly class InstallUiCommand
{
    public const string COMMAND = 'vector:install-ui';

    /**
     * @param ?UiTarballSource $source the registry; HttpsTarballSource on
     *     VECTOR_UI_REGISTRY unless a test gives another
     */
    public function __construct(
        private Output $output,
        private ?UiTarballSource $source = null,
    ) {}

    #[Command('vector:install-ui', description: 'Installs the hosted admin UI (@kinetis/vector-ui) into VECTOR_UI_DIR. --version=X.Y.Z, --from=<tgz|dir> [--integrity=sha512-…], --check, --force.', bootstrap: false)]
    public function installUi(CommandArguments $arguments): int
    {
        return $this->run($arguments, Config::fromEnvironment(), ProjectRoot::detect(dirname(__DIR__)), UiPin::path(), InstallCommand::devInstall());
    }

    /**
     * The command, with the configuration, the project root, the pin and
     * whether kinetis/vector is a dev install given rather than read.
     *
     * @internal public so tests can run it on a fixture project; installUi() is the command
     */
    public function run(CommandArguments $arguments, Config $config, string $projectRoot, string $pinFile, bool $devInstall): int
    {
        return new TarballInstaller($this->output, UiPin::PACKAGE, self::COMMAND, [], $this->source)
            ->run($arguments, $config, $projectRoot, $pinFile, $devInstall);
    }
}
