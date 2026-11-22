<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Console;

use Kinetis\Config\Config;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Vector\Ui\TarballInstaller;
use Kinetis\Vector\Ui\UiPin;
use Kinetis\Vector\Ui\UiTarballSource;

/**
 * Installs the hosted admin with AI — the bundle @kinetis/vector-ai-ui
 * ships in `dist/static`, built from @kinetis/vector-ui plus the AI layer
 * — into the served directory, in place of Vector's plain one, through
 * kinetis/vector's TarballInstaller: the same options, checks and exit
 * codes as `vector:install-ui`. The bundle must be the release
 * `resources/ui.json` pins and include the @kinetis/vector-ui release
 * kinetis/vector pins. No bootstrap: this touches files and the registry
 * only.
 *
 *     kinetis vector-ai:install-ui                    the version resources/ui.json pins
 *     kinetis vector-ai:install-ui --version=X.Y.Z    another published version
 *     kinetis vector-ai:install-ui --from=<tgz|dir> [--integrity=sha512-…] [--version=X.Y.Z]
 *     kinetis vector-ai:install-ui --check [--version=X.Y.Z]
 *     kinetis vector-ai:install-ui --force            reinstall what is installed already
 */
final readonly class InstallUiCommand
{
    public const string PACKAGE = '@kinetis/vector-ai-ui';

    public const string COMMAND = 'vector-ai:install-ui';

    public const string COMPOSER_PACKAGE = 'kinetis/vector-ai';

    /**
     * @param ?UiTarballSource $source the registry; HttpsTarballSource on
     *     VECTOR_UI_REGISTRY unless a test gives another
     */
    public function __construct(
        private Output $output,
        private ?UiTarballSource $source = null,
    ) {}

    /** The pin this package ships. */
    public static function pinPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/ui.json';
    }

    #[Command('vector-ai:install-ui', description: 'Installs the hosted admin with AI (@kinetis/vector-ai-ui) into VECTOR_UI_DIR, in place of Vector\'s plain one. --version=X.Y.Z, --from=<tgz|dir> [--integrity=sha512-…], --check, --force.', bootstrap: false)]
    public function installUi(CommandArguments $arguments): int
    {
        return $this->run($arguments, Config::fromEnvironment(), ProjectRoot::detect(dirname(__DIR__)), self::pinPath(), UiPin::path(), TarballInstaller::devInstall(self::COMPOSER_PACKAGE));
    }

    /**
     * The command, with the configuration, the project root, both pins and
     * whether kinetis/vector-ai is a dev install given rather than read.
     *
     * @internal public so tests can run it on a fixture project; installUi() is the command
     */
    public function run(CommandArguments $arguments, Config $config, string $projectRoot, string $pinFile, string $vectorPinFile, bool $devInstall): int
    {
        $vector = UiPin::tryFromFile($vectorPinFile);

        if ($vector === null) {
            $this->output->error("kinetis/vector pins no " . UiPin::PACKAGE . " release: {$vectorPinFile} is missing or malformed. Reinstall kinetis/vector.");

            return 1;
        }

        return new TarballInstaller($this->output, self::PACKAGE, self::COMMAND, [UiPin::PACKAGE => $vector->version], $this->source)
            ->run($arguments, $config, $projectRoot, $pinFile, $devInstall);
    }
}
