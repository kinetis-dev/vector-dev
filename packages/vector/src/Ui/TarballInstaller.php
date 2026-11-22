<?php

declare(strict_types=1);

namespace Kinetis\Vector\Ui;

use Composer\InstalledVersions;
use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;

/**
 * Installs a hosted admin bundle published on npm into the served
 * directory: `VECTOR_UI_DIR`, else `public/vector/ui`. The bundle is
 * @kinetis/vector-ui's own, or a package's that ships one built from
 * Vector's layer plus its own and names, in its manifest's `includes`,
 * the @kinetis/vector-ui release it was built with. `vector:install-ui`
 * runs it for @kinetis/vector-ui; such a package's own install command
 * runs it for its bundle, with the @kinetis/vector-ui version
 * kinetis/vector pins as an include the bundle must carry.
 *
 *     <command>                       the version the pin names
 *     <command> --version=X.Y.Z       another published version
 *     <command> --from=<tgz|dir> [--integrity=sha512-…] [--version=X.Y.Z]
 *     <command> --check [--version=X.Y.Z]
 *     <command> --force               reinstall what is installed already
 *
 * From the registry (`VECTOR_UI_REGISTRY`, npm's by default; a bearer
 * `VECTOR_UI_REGISTRY_TOKEN` for a private one) the tarball is
 * downloaded over https and checked against the registry's sha512
 * `dist.integrity` before anything is extracted. `--from` takes a packed
 * tarball, checked against `--integrity` when one is given, or a built
 * `dist/static` directory. Either way the bundle must be the expected
 * package and version, carry the expected includes and speak this
 * kinetis/vector's descriptor contract; on a dev install a dev build
 * (`dev-<sha>`) is accepted too. UiInstaller then swaps it in,
 * `index.html` and `vector-bundle.json` last; a refusal writes nothing
 * there.
 *
 * `--check` verifies the served directory against its manifest and the
 * expected version, with no network: exit 0 in sync, 1 otherwise. A
 * served bundle of another package that includes this one at the
 * expected version is in sync too. Exit codes: 0 done, 1 refused or
 * failed, 2 a usage error. Results go to stdout and refusals to stderr.
 */
final readonly class TarballInstaller
{
    private const string EXACT_VERSION = '/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/D';

    /**
     * @param string $package the npm package the bundle is, as its pin names it
     * @param string $command the console command running this, named in usage and remedies
     * @param array<string, string> $includes npm package => the exact version the bundle must include
     * @param ?UiTarballSource $source the registry; HttpsTarballSource on
     *     VECTOR_UI_REGISTRY unless a test gives another
     */
    public function __construct(
        private Output $output,
        private string $package,
        private string $command,
        private array $includes = [],
        private ?UiTarballSource $source = null,
    ) {}

    /**
     * Whether the Composer package $composerPackage is a dev install (a
     * path repository, a branch, or not installed through Composer), on
     * which a dev build of its UI (`dev-<sha>`) is accepted in place of
     * the pinned release.
     */
    public static function devInstall(string $composerPackage): bool
    {
        try {
            $version = InstalledVersions::getPrettyVersion($composerPackage);
        } catch (\OutOfBoundsException) {
            $version = null;
        }

        return self::release($version) === null;
    }

    /**
     * The release an installed version names, without its leading "v",
     * or null for a dev install: a path repository or a branch.
     */
    public static function release(?string $prettyVersion): ?string
    {
        if ($prettyVersion === null || str_contains($prettyVersion, 'dev')) {
            return null;
        }

        return str_starts_with($prettyVersion, 'v') ? substr($prettyVersion, 1) : $prettyVersion;
    }

    /**
     * Runs the command's options against the served directory, with the
     * configuration, the project root, the pin and whether the package is
     * a dev install given rather than read.
     */
    public function run(CommandArguments $arguments, Config $config, string $projectRoot, string $pinFile, bool $devInstall): int
    {
        $usage = $this->usageProblem($arguments);

        if ($usage !== null) {
            $this->output->error($usage);
            $this->output->error($this->usage());

            return 2;
        }

        $target = VectorUi::directory($config, $projectRoot);
        $expected = $arguments->option('version') ?? UiPin::tryFromFile($pinFile, $this->package)?->version;

        if ($expected === null) {
            $this->output->error("No UI version is pinned: {$pinFile} is missing or malformed. Name one with --version=X.Y.Z.");

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
            $this->output->error($e->getMessage());

            return 1;
        }
    }

    private function usage(): string
    {
        return "Usage: {$this->command} [--version=X.Y.Z] [--force] | --from=<tgz|dir> [--integrity=sha512-…] [--version=X.Y.Z] | --check [--version=X.Y.Z]";
    }

    /** Why these arguments are not a valid invocation, or null when they are. */
    private function usageProblem(CommandArguments $arguments): ?string
    {
        if ($arguments->all() !== []) {
            return "{$this->command} takes no positional argument.";
        }

        return self::optionValueProblem($arguments)
            ?? self::optionCombinationProblem($arguments)
            ?? self::optionFormatProblem($arguments);
    }

    /** An option that needs a value given none, or a flag given one. */
    private static function optionValueProblem(CommandArguments $arguments): ?string
    {
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

        return null;
    }

    /** Options that do not go together. */
    private static function optionCombinationProblem(CommandArguments $arguments): ?string
    {
        if ($arguments->hasOption('check') && ($arguments->hasOption('from') || $arguments->hasOption('integrity') || $arguments->hasOption('force'))) {
            return '--check takes no option but --version.';
        }

        if ($arguments->hasOption('integrity') && !$arguments->hasOption('from')) {
            return '--integrity checks a --from tarball; give --from=<tgz> with it.';
        }

        if ($arguments->hasOption('integrity') && is_dir((string) $arguments->option('from'))) {
            return '--integrity checks a --from tarball; --from names a directory.';
        }

        return null;
    }

    /** A --version or --integrity value of the wrong form. */
    private static function optionFormatProblem(CommandArguments $arguments): ?string
    {
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
        $manifest = $target . '/' . HostedBundle::MANIFEST;

        if (!is_file($manifest)) {
            $this->output->error("{$target}: cannot verify: no " . HostedBundle::MANIFEST . ". Run `vendor/bin/kinetis {$this->command}` to install {$this->package} {$expected}.");

            return 1;
        }

        $served = HostedBundle::read($manifest);
        $included = $served === null || $served->package === $this->package ? null : ($served->includes[$this->package] ?? null);

        try {
            if ($served !== null && $included !== null) {
                // another package's bundle, built with this one: hold what it includes to the expectation
                $bundle = HostedBundle::verify($target, null, $served->package, [
                    $this->package => UiInstaller::accepts($included, $expected, $devInstall) ? $included : $expected,
                ], $this->command);
                $this->output->line("{$bundle->package} {$bundle->version} in {$target} includes {$this->package} {$included}, verifies against its " . HostedBundle::MANIFEST . ' and is in sync.');

                return 0;
            }

            $bundle = HostedBundle::verify($target, UiInstaller::expectedFor($target, $expected, $devInstall), $this->package, $this->includes, $this->command);
        } catch (\UnexpectedValueException $e) {
            $this->output->error($e->getMessage());

            return 1;
        }

        $this->output->line("{$this->package} {$bundle->version} in {$target} verifies against its " . HostedBundle::MANIFEST . ' and is in sync.');

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
            $this->output->line("{$this->package} {$expected} is already installed in {$target} and verifies; nothing to do. --force reinstalls it.");

            return 0;
        }

        $token = trim($config->string('VECTOR_UI_REGISTRY_TOKEN', ''));
        $source = $this->source ?? new HttpsTarballSource($registry, $token === '' ? null : $token);
        ['tarball' => $tarball, 'integrity' => $integrity] = $source->resolve($this->package, $expected);

        if (HttpsTarballSource::origin($tarball) === null) {
            throw new \UnexpectedValueException("Refusing the tarball the registry names for {$this->package}@{$expected}: it is not an https URL.");
        }

        if ($integrity === null || self::sha512Digests($integrity) === []) {
            throw new \UnexpectedValueException("Refusing {$this->package}@{$expected}: the registry gives no sha512 dist.integrity to check the tarball against.");
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
            throw new \UnexpectedValueException("Refusing the tarball: its sha512 does not match the integrity {$this->package} was published with.");
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
        $bundle = new UiInstaller()->install($stage, $target, UiInstaller::expectedFor($stage, $expected, $devInstall), $this->package, $this->includes, $this->command);

        $this->output->line("Installed {$this->package} {$bundle->version} (" . count($bundle->files) . " files) into {$target}. Restart workers so they serve the new shell.");

        return 0;
    }

    /** Whether $expected itself is installed in $target and verifies. */
    private function installed(string $target, string $expected): bool
    {
        $served = HostedBundle::read($target . '/' . HostedBundle::MANIFEST);

        if ($served?->package !== $this->package || $served->version !== $expected) {
            return false;
        }

        try {
            HostedBundle::verify($target, $expected, $this->package, $this->includes, $this->command);

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
                throw new UiInstallException("Could not create {$dir}.");
            }

            if (!copy("{$from}/{$path}", "{$stage}/{$path}") || !chmod("{$stage}/{$path}", 0644)) {
                throw new UiInstallException("Could not copy {$from}/{$path}.");
            }
        }
    }
}
