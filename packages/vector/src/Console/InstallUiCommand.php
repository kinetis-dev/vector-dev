<?php

declare(strict_types=1);

namespace Kinetis\Vector\Console;

use Kinetis\Config\Config;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Vector\Ui\HostedBundle;
use Kinetis\Vector\Ui\HttpsTarballSource;
use Kinetis\Vector\Ui\TarReader;
use Kinetis\Vector\Ui\UiInstaller;
use Kinetis\Vector\Ui\UiPin;
use Kinetis\Vector\Ui\UiTarballSource;
use Kinetis\Vector\Ui\VectorUi;

/**
 * Installs the hosted admin UI — the bundle @kinetis/vector-ui ships in
 * `dist/static` — into the served directory: `VECTOR_UI_DIR`, else
 * `public/vector/ui`. No bootstrap: this touches files and the registry
 * only.
 *
 *     kinetis vector:install-ui                       the version resources/ui.json pins
 *     kinetis vector:install-ui --version=X.Y.Z       another published version
 *     kinetis vector:install-ui --from=<tgz|dir> [--integrity=sha512-…] [--version=X.Y.Z]
 *     kinetis vector:install-ui --check [--version=X.Y.Z]
 *     kinetis vector:install-ui --force               reinstall what is installed already
 *
 * From the registry (`VECTOR_UI_REGISTRY`, npm's by default; a bearer
 * `VECTOR_UI_REGISTRY_TOKEN` for a private one) the tarball is
 * downloaded over https and checked against the registry's sha512
 * `dist.integrity` before anything is extracted. `--from` takes a packed
 * tarball, checked against `--integrity` when one is given, or a built
 * `dist/static` directory. Either way the bundle must be the expected
 * version and speak this kinetis/vector's descriptor contract; on a dev
 * install of kinetis/vector a dev build (`dev-<sha>`) is accepted too.
 * UiInstaller then swaps it in, `index.html` and `vector-bundle.json`
 * last; a refusal writes nothing there.
 *
 * `--check` verifies the served directory against its manifest and the
 * expected version, with no network: exit 0 in sync, 1 otherwise.
 * Exit codes: 0 done, 1 refused or failed, 2 a usage error.
 *
 * $output and $errors are injectable (defaulting to STDOUT and STDERR),
 * as is the registry source, for testability.
 */
final readonly class InstallUiCommand
{
    private const string USAGE = 'Usage: vector:install-ui [--version=X.Y.Z] [--force] | --from=<tgz|dir> [--integrity=sha512-…] [--version=X.Y.Z] | --check [--version=X.Y.Z]';

    private const string EXACT_VERSION = '/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/D';

    /**
     * @param resource $output mixed, not resource, since PHP has no native
     *     "resource" type and a readonly property requires one
     * @param resource $errors the same, for refusals
     * @param ?UiTarballSource $source the registry; HttpsTarballSource on
     *     VECTOR_UI_REGISTRY unless a test gives another
     */
    public function __construct(
        private mixed $output = STDOUT,
        private mixed $errors = STDERR,
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
        $usage = self::usageProblem($arguments);

        if ($usage !== null) {
            fwrite($this->errors, $usage . "\n" . self::USAGE . "\n");

            return 2;
        }

        $target = VectorUi::directory($config, $projectRoot);
        $expected = $arguments->option('version') ?? UiPin::tryFromFile($pinFile)?->version;

        if ($expected === null) {
            fwrite($this->errors, "No UI version is pinned: {$pinFile} is missing or malformed. Name one with --version=X.Y.Z.\n");

            return 1;
        }

        if ($arguments->hasOption('check')) {
            return $this->check($target, $expected, $devInstall);
        }

        try {
            $from = $arguments->option('from');

            return $from !== null
                ? $this->installFrom($from, $arguments->option('integrity'), $target, $expected, $devInstall)
                : $this->installFromRegistry($config, $target, $expected, $devInstall, $arguments->hasOption('force'));
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            fwrite($this->errors, $e->getMessage() . "\n");

            return 1;
        }
    }

    /** Why these arguments are not a valid invocation, or null when they are. */
    private static function usageProblem(CommandArguments $arguments): ?string
    {
        if ($arguments->all() !== []) {
            return 'vector:install-ui takes no positional argument.';
        }

        foreach (['version', 'from', 'integrity'] as $name) {
            if ($arguments->hasOption($name) && in_array($arguments->option($name), [null, ''], true)) {
                return "--{$name} needs a value: --{$name}=<value>.";
            }
        }

        foreach (['check', 'force'] as $name) {
            if ($arguments->hasOption($name) && $arguments->option($name) !== null) {
                return "--{$name} takes no value.";
            }
        }

        if ($arguments->hasOption('check') && ($arguments->hasOption('from') || $arguments->hasOption('integrity') || $arguments->hasOption('force'))) {
            return '--check takes no option but --version.';
        }

        if ($arguments->hasOption('integrity') && !$arguments->hasOption('from')) {
            return '--integrity checks a --from tarball; give --from=<tgz> with it.';
        }

        if ($arguments->hasOption('integrity') && is_dir((string) $arguments->option('from'))) {
            return '--integrity checks a --from tarball; --from names a directory.';
        }

        $version = $arguments->option('version');

        if ($version !== null && preg_match(self::EXACT_VERSION, $version) !== 1) {
            return "--version={$version} is not an exact X.Y.Z version.";
        }

        $integrity = $arguments->option('integrity');

        if ($integrity !== null && self::sha512Digests($integrity) === []) {
            return '--integrity needs a sha512 SRI string: --integrity=sha512-<base64>.';
        }

        return null;
    }

    /** --check: the served directory against its manifest and the expected version, offline. */
    private function check(string $target, string $expected, bool $devInstall): int
    {
        if (!is_file($target . '/' . HostedBundle::MANIFEST)) {
            fwrite($this->errors, "{$target}: cannot verify: no " . HostedBundle::MANIFEST . ". Run `vendor/bin/kinetis vector:install-ui` to install " . UiPin::PACKAGE . " {$expected}.\n");

            return 1;
        }

        try {
            $bundle = HostedBundle::verify($target, UiInstaller::expectedFor($target, $expected, $devInstall));
        } catch (\UnexpectedValueException $e) {
            fwrite($this->errors, $e->getMessage() . "\n");

            return 1;
        }

        fwrite($this->output, UiPin::PACKAGE . " {$bundle->version} in {$target} verifies against its " . HostedBundle::MANIFEST . " and is in sync.\n");

        return 0;
    }

    /**
     * The expected version from the registry: resolved, downloaded,
     * checked against its sha512 integrity, extracted and installed.
     * Nothing is fetched when that version is installed and verifies,
     * unless $force.
     */
    private function installFromRegistry(Config $config, string $target, string $expected, bool $devInstall, bool $force): int
    {
        $registry = trim($config->string('VECTOR_UI_REGISTRY', ''));
        $registry = $registry === '' ? HttpsTarballSource::DEFAULT_REGISTRY : $registry;

        if (HttpsTarballSource::origin($registry) === null) {
            throw new \UnexpectedValueException('Refusing VECTOR_UI_REGISTRY: it must be an https URL without credentials, such as ' . HttpsTarballSource::DEFAULT_REGISTRY . '.');
        }

        if (!$force && $this->installed($target, $expected)) {
            fwrite($this->output, UiPin::PACKAGE . " {$expected} is already installed in {$target} and verifies; nothing to do. --force reinstalls it.\n");

            return 0;
        }

        $token = trim($config->string('VECTOR_UI_REGISTRY_TOKEN', ''));
        $source = $this->source ?? new HttpsTarballSource($registry, $token === '' ? null : $token);
        ['tarball' => $tarball, 'integrity' => $integrity] = $source->resolve(UiPin::PACKAGE, $expected);

        if (HttpsTarballSource::origin($tarball) === null) {
            throw new \UnexpectedValueException('Refusing the tarball the registry names for ' . UiPin::PACKAGE . "@{$expected}: it is not an https URL.");
        }

        if ($integrity === null || self::sha512Digests($integrity) === []) {
            throw new \UnexpectedValueException('Refusing ' . UiPin::PACKAGE . "@{$expected}: the registry gives no sha512 dist.integrity to check the tarball against.");
        }

        $work = UiInstaller::tempDir('download');

        try {
            $archive = $work . '/ui.tgz';
            $source->download($tarball, $archive, TarReader::MAX_DOWNLOAD);

            return $this->installArchive($archive, $integrity, $target, $expected, $devInstall);
        } finally {
            UiInstaller::remove($work);
        }
    }

    /** --from: a packed tarball or a built dist/static directory. */
    private function installFrom(string $from, ?string $integrity, string $target, string $expected, bool $devInstall): int
    {
        if (is_dir($from)) {
            $stage = UiInstaller::tempDir('stage');

            try {
                self::copyTree($from, $stage);

                return $this->installStage($stage, $target, $expected, $devInstall);
            } finally {
                UiInstaller::remove($stage);
            }
        }

        if (!is_file($from) || !(str_ends_with($from, '.tgz') || str_ends_with($from, '.tar.gz'))) {
            throw new \UnexpectedValueException("--from={$from} is neither a .tgz tarball nor a directory.");
        }

        return $this->installArchive($from, $integrity, $target, $expected, $devInstall);
    }

    /**
     * A tarball: its size and, when $integrity is given, its sha512 checked
     * before a byte of it is extracted.
     */
    private function installArchive(string $archive, ?string $integrity, string $target, string $expected, bool $devInstall): int
    {
        $size = filesize($archive);

        if ($size === false || $size > TarReader::MAX_DOWNLOAD) {
            throw new \UnexpectedValueException('Refusing the tarball: it is larger than ' . TarReader::MAX_DOWNLOAD . ' bytes.');
        }

        if ($integrity !== null && !self::integrityMatches($archive, $integrity)) {
            throw new \UnexpectedValueException('Refusing the tarball: its sha512 does not match the integrity ' . UiPin::PACKAGE . ' was published with.');
        }

        $stage = UiInstaller::tempDir('stage');

        try {
            new TarReader()->extract($archive, $stage);

            return $this->installStage($stage, $target, $expected, $devInstall);
        } finally {
            UiInstaller::remove($stage);
        }
    }

    private function installStage(string $stage, string $target, string $expected, bool $devInstall): int
    {
        $bundle = new UiInstaller()->install($stage, $target, UiInstaller::expectedFor($stage, $expected, $devInstall));

        fwrite($this->output, 'Installed ' . UiPin::PACKAGE . " {$bundle->version} (" . count($bundle->files) . " files) into {$target}. Restart workers so they serve the new shell.\n");

        return 0;
    }

    /** Whether $expected itself is installed in $target and verifies. */
    private function installed(string $target, string $expected): bool
    {
        if (HostedBundle::read($target . '/' . HostedBundle::MANIFEST)?->version !== $expected) {
            return false;
        }

        try {
            HostedBundle::verify($target, $expected);

            return true;
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    /**
     * The sha512 digests an SRI string names, raw; an SRI string may list
     * several hashes, of several algorithms. Empty when it names none.
     *
     * @return list<string>
     */
    private static function sha512Digests(string $sri): array
    {
        $digests = [];

        foreach (preg_split('/\s+/', trim($sri)) ?: [] as $token) {
            if (preg_match('/^sha512-([A-Za-z0-9+\/]{86}==)(?:\?\S*)?$/D', $token, $match) === 1) {
                $digests[] = (string) base64_decode($match[1], true);
            }
        }

        return array_values(array_filter($digests, static fn (string $digest): bool => strlen($digest) === 64));
    }

    private static function integrityMatches(string $file, string $sri): bool
    {
        $actual = hash_file('sha512', $file, true);

        foreach (self::sha512Digests($sri) as $digest) {
            if (is_string($actual) && hash_equals($digest, $actual)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Copies a built dist/static directory into the stage, held to the
     * same limits a tarball is: regular files and directories only, files
     * 0644 and directories 0755.
     */
    private static function copyTree(string $from, string $stage): void
    {
        $files = UiInstaller::files($from);
        $total = 0;

        if (count($files) > TarReader::MAX_ENTRIES) {
            throw new \UnexpectedValueException("{$from} holds more than " . TarReader::MAX_ENTRIES . ' files.');
        }

        foreach ($files as $path) {
            if ($path !== HostedBundle::MANIFEST && !HostedBundle::isRelativePath($path)) {
                throw new \UnexpectedValueException("{$from} holds a file the bundle cannot serve: {$path}.");
            }

            $size = (int) filesize("{$from}/{$path}");
            $total += $size;

            if ($size > TarReader::MAX_ENTRY || $total > TarReader::MAX_TOTAL) {
                throw new \UnexpectedValueException("{$from} is larger than a bundle may be.");
            }

            $dir = dirname("{$stage}/{$path}");

            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Could not create {$dir}.");
            }

            if (!copy("{$from}/{$path}", "{$stage}/{$path}") || !chmod("{$stage}/{$path}", 0644)) {
                throw new \RuntimeException("Could not copy {$from}/{$path}.");
            }
        }
    }
}
