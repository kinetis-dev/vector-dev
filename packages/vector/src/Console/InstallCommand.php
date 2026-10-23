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
use Kinetis\Vector\Ui\UiInstaller;
use Kinetis\Vector\Ui\UiPin;
use Kinetis\Vector\Ui\VectorUi;

/**
 * Publishes Vector's migrations into the application. No bootstrap: this
 * touches files only. The hosted admin UI is `vector:install-ui`'s; this
 * command only says when the served one is missing or is not the
 * @kinetis/vector-ui release this kinetis/vector pins.
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
 */
final readonly class InstallCommand
{
    /** The Composer package; the UI is the npm package UiPin::PACKAGE names. */
    public const string COMPOSER_PACKAGE = 'kinetis/vector';

    #[Command('vector:install', description: "Publishes Vector's migrations. --connection=<name> puts Vector's tables on a named connection.", bootstrap: false)]
    public function install(CommandArguments $arguments): int
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
        $drift = self::uiDrift(VectorUi::directory($config, $root), UiPin::tryFromFile(UiPin::path()), self::devInstall());

        if ($drift !== null) {
            echo $drift . "\n";
        }

        return 0;
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
            return InstalledVersions::getPrettyVersion(self::COMPOSER_PACKAGE);
        } catch (\OutOfBoundsException) {
            return null;
        }
    }

    /**
     * The release an installed version names, without its leading "v",
     * or null for a dev install — a path repository or a branch.
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
     * Whether kinetis/vector is a dev install — a path repository, a
     * branch, or not installed through Composer — on which a dev build
     * of the UI (`dev-<sha>`) is accepted in place of the pinned release.
     */
    public static function devInstall(): bool
    {
        return self::release(self::installedVersion()) === null;
    }

    /**
     * The note to print when the UI served from $dir is not the pinned
     * release, or null when it is (or nothing is pinned): none installed,
     * one without a vector-bundle.json, or another version.
     */
    private static function uiDrift(string $dir, ?UiPin $pin, bool $devInstall): ?string
    {
        if ($pin === null) {
            return null;
        }

        $remedy = "Run `vendor/bin/kinetis vector:install-ui` to install " . UiPin::PACKAGE . " {$pin->version}, then restart workers.";
        $manifest = $dir . '/' . HostedBundle::MANIFEST;

        if (!is_file($manifest)) {
            return is_file($dir . '/index.html')
                ? "Note: the admin UI in {$dir} has no " . HostedBundle::MANIFEST . ", so its version is unknown. {$remedy}"
                : "Note: no admin UI is installed in {$dir}. {$remedy}";
        }

        $version = HostedBundle::read($manifest)?->version;

        if ($version === null) {
            return "Note: the admin UI in {$dir} has a malformed " . HostedBundle::MANIFEST . ". {$remedy}";
        }

        return UiInstaller::accepts($version, $pin->version, $devInstall)
            ? null
            : "Note: the admin UI in {$dir} is " . UiPin::PACKAGE . " {$version}; this kinetis/vector expects {$pin->version}. {$remedy}";
    }
}
