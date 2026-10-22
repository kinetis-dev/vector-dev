<?php

declare(strict_types=1);

namespace Kinetis\Vector\Console;

use Composer\InstalledVersions;
use Kinetis\Config\Config;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\Vector\Ui\HostedBundle;
use Kinetis\Vector\Ui\VectorUi;

/**
 * Publishes what Vector needs inside the application: its migrations and,
 * when built, the hosted UI bundle. No bootstrap: this touches files only.
 *
 *     kinetis vector:install                     Vector's tables on the default connection
 *     kinetis vector:install --connection=admin  on the named connection "admin"
 *
 * The migrations are copied, each under the timestamp its stub carries,
 * into the partition
 * kinetis/migrations runs on that connection — `migrations/` for the
 * default one, `migrations/<name>/` for a named one — in the dialect the
 * connection's `DB_CONNECTION` or `DB_<NAME>_CONNECTION` key names:
 * `mysql` or `pgsql`. Nothing is overwritten; run `kinetis migrate`
 * afterwards. A named connection also needs `VECTOR_DB_CONNECTION=<name>`
 * in the application's configuration, which this command prints.
 *
 * The hosted UI is published only when `resources/dist` matches its
 * manifest, `vector-bundle.json` (see HostedBundle): a bundle another
 * version or descriptor contract built, or one whose files changed after
 * the build, is refused with exit 1 before any migration is published,
 * and nothing is copied.
 */
final readonly class InstallCommand
{

    #[Command('vector:install', description: "Publishes Vector's migrations and hosted UI. --connection=<name> puts Vector's tables on a named connection.", bootstrap: false)]
    public function install(CommandArguments $arguments): int
    {
        return $this->installFrom($arguments, dirname(__DIR__, 2) . '/resources/dist', self::release(self::installedVersion()));
    }

    /**
     * The command, with the bundle directory and the installed release
     * given rather than read from the package and from Composer.
     *
     * @internal public so tests can install a fixture bundle as a release
     *           install would; install() is the command
     *
     * @param ?string $release the installed release, null for a dev install
     */
    public function installFrom(CommandArguments $arguments, string $dist, ?string $release): int
    {
        $root = ProjectRoot::detect(dirname(__DIR__));
        $config = Config::fromEnvironment();

        try {
            $connection = self::connection($arguments);
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 2;
        }

        $key = Config::scopedKey('DB_CONNECTION', $connection);
        $dialect = $config->get($key);

        if (!in_array($dialect, ['mysql', 'pgsql'], true)) {
            fwrite(STDERR, $dialect === null
                ? "{$key} is not set, so the \"{$connection}\" connection's dialect is unknown. Configure the connection first.\n"
                : "{$key} is \"{$dialect}\"; Vector supports mysql and pgsql.\n");

            return 1;
        }

        // The bundle is verified before anything is written, so a refused
        // one leaves no partial install behind.
        try {
            $bundle = self::bundle($dist, $release);
        } catch (\UnexpectedValueException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }

        $migrations = $root . '/migrations';
        $target = $connection === 'default' ? $migrations : "{$migrations}/{$connection}";

        foreach ([$migrations, $target] as $directory) {
            if (!self::directory($directory)) {
                fwrite(STDERR, "Could not create {$directory}.\n");

                return 1;
            }
        }

        $published = $this->publishMigrations($root, $target, $dialect);

        if ($published === null) {
            return 1;
        }

        $this->printNextSteps($migrations, $connection, $published, $config);

        return $this->publishUi($dist, $root . '/public' . VectorUi::PATH, $bundle, $release);
    }

    /**
     * The connection --connection names, "default" without the option.
     *
     * @throws \InvalidArgumentException when the option has no value or names no valid connection
     */
    private static function connection(CommandArguments $arguments): string
    {
        return VectorDatabase::validated(
            $arguments->hasOption('connection')
                ? ($arguments->option('connection') ?? throw new \InvalidArgumentException('--connection needs a value: --connection=<name>.'))
                : 'default',
            '--connection',
        );
    }

    /**
     * The verified bundle in $dist, or null when it holds neither a shell
     * nor a manifest.
     *
     * @param ?string $release the installed release, null for a dev install
     *
     * @throws \UnexpectedValueException when the bundle is refused
     */
    private static function bundle(string $dist, ?string $release): ?HostedBundle
    {
        if (!is_file($dist . '/index.html') && !is_file($dist . '/' . HostedBundle::MANIFEST)) {
            return null;
        }

        return HostedBundle::verify($dist, $release);
    }

    /**
     * Whether $directory exists, creating it when it does not.
     */
    private static function directory(string $directory): bool
    {
        return is_dir($directory) || mkdir($directory, 0755) || is_dir($directory);
    }

    /**
     * Copies each migration stub of $dialect that $target does not hold
     * yet into it, naming every one published.
     *
     * @return ?int how many were published, null when one could not be written
     */
    private function publishMigrations(string $root, string $target, string $dialect): ?int
    {
        $published = 0;

        foreach (glob(dirname(__DIR__, 2) . "/resources/migrations/*.{$dialect}.stub") ?: [] as $stub) {
            $name = basename($stub, ".{$dialect}.stub") . '.php';
            $file = "{$target}/{$name}";

            if (file_exists($file)) {
                continue;
            }

            $contents = file_get_contents($stub);

            if ($contents === false || file_put_contents($file, $contents) === false) {
                fwrite(STDERR, "Could not write {$file}.\n");

                return null;
            }

            echo 'Published ' . substr($file, strlen($root) + 1) . "\n";
            $published++;
        }

        return $published;
    }

    /**
     * What to run next, the other connections holding Vector's
     * migrations, and the VECTOR_DB_CONNECTION change the install needs.
     */
    private function printNextSteps(string $migrations, string $connection, int $published, Config $config): void
    {
        $option = $connection === 'default' ? '' : " --connection={$connection}";

        echo $published === 0
            ? "Nothing to publish: every Vector migration is already present for the \"{$connection}\" connection.\n"
            : "Run `vendor/bin/kinetis migrate{$option}` to apply.\n";

        foreach ($this->otherPartitions($migrations, $connection) as $other) {
            echo "Note: Vector's migrations are also published for the \"{$other}\" connection. Vector uses the one VECTOR_DB_CONNECTION names.\n";
        }

        $configured = trim($config->string('VECTOR_DB_CONNECTION', ''));

        if (($configured === '' ? 'default' : $configured) !== $connection) {
            echo $connection === 'default'
                ? "Remove VECTOR_DB_CONNECTION (currently \"{$configured}\") so Vector uses the default connection.\n"
                : "Set VECTOR_DB_CONNECTION={$connection} in the application's configuration so Vector uses these tables.\n";
        }
    }

    /**
     * The connections other than $connection that already hold Vector's
     * migrations.
     *
     * @return list<string>
     */
    private function otherPartitions(string $migrations, string $connection): array
    {
        $others = [];

        if ($connection !== 'default' && glob("{$migrations}/*_vector_*.php") !== []) {
            $others[] = 'default';
        }

        foreach (glob("{$migrations}/*", GLOB_ONLYDIR) ?: [] as $directory) {
            $name = basename($directory);

            if ($name !== $connection && glob("{$directory}/*_vector_*.php") !== []) {
                $others[] = $name;
            }
        }

        return $others;
    }

    /**
     * The installed kinetis/vector version as Composer records it, or
     * null when Composer does not know the package as installed.
     */
    private static function installedVersion(): ?string
    {
        try {
            return InstalledVersions::getPrettyVersion(HostedBundle::PACKAGE);
        } catch (\OutOfBoundsException) {
            return null;
        }
    }

    /**
     * The release an installed version names, without its leading "v",
     * or null for a dev install — a path repository or a branch — whose
     * bundle is whatever the last local build left.
     *
     * @internal public so tests can cover the mapping; install() is the command
     */
    public static function release(?string $prettyVersion): ?string
    {
        if ($prettyVersion === null || str_contains($prettyVersion, 'dev')) {
            return null;
        }

        return str_starts_with($prettyVersion, 'v') ? substr($prettyVersion, 1) : $prettyVersion;
    }

    /**
     * Copies the built admin bundle into the web root, replacing what an
     * earlier install left there, so the shell always references files
     * that exist. $bundle is the verified bundle in $dist, null when
     * there is none; the manifest itself stays out of the web root.
     *
     * @param ?string $release the installed release, null for a dev install
     */
    private function publishUi(string $dist, string $target, ?HostedBundle $bundle, ?string $release): int
    {
        if ($bundle === null) {
            echo "No hosted admin bundle in {$dist}: a source install of kinetis/vector has none; released packages ship it. The hosted admin stays unpublished unless VECTOR_UI_DIR points at a separately built bundle. Migrations and the API work without it.\n";

            return 0;
        }

        if ($release === null) {
            echo "Publishing a development build of the admin UI: version {$bundle->version}, built from {$bundle->source}.\n";
        }

        $files = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dist, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $item) {
            $relative = substr((string) $item->getPathname(), strlen($dist) + 1);

            if ($relative === HostedBundle::MANIFEST) {
                continue;
            }

            $destination = $target . '/' . $relative;

            if ($item->isDir()) {
                if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
                    fwrite(STDERR, "Could not create {$destination}.\n");

                    return 1;
                }

                continue;
            }

            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0755, true);
            }

            if (!copy((string) $item->getPathname(), $destination)) {
                fwrite(STDERR, "Could not copy {$relative}.\n");

                return 1;
            }

            $files++;
        }

        echo "Published the admin UI ({$files} files) to {$target}. Restart workers so they read the new shell.\n";

        return 0;
    }
}
