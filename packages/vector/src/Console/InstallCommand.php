<?php

declare(strict_types=1);

namespace Kinetis\Vector\Console;

use Kinetis\Config\Config;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Vector\Database\VectorDatabase;
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
 */
final readonly class InstallCommand
{

    #[Command('vector:install', description: "Publishes Vector's migrations and hosted UI. --connection=<name> puts Vector's tables on a named connection.", bootstrap: false)]
    public function install(CommandArguments $arguments): int
    {
        $root = ProjectRoot::detect(dirname(__DIR__));
        $config = Config::fromEnvironment();

        try {
            $connection = VectorDatabase::validated(
                $arguments->hasOption('connection')
                    ? ($arguments->option('connection') ?? throw new \InvalidArgumentException('--connection needs a value: --connection=<name>.'))
                    : 'default',
                '--connection',
            );
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

        if (!is_dir($migrations)) {
            fwrite(STDERR, "No migrations/ directory at {$root}; install kinetis/migrations first.\n");

            return 1;
        }

        $target = $connection === 'default' ? $migrations : "{$migrations}/{$connection}";

        if (!is_dir($target) && !mkdir($target, 0755) && !is_dir($target)) {
            fwrite(STDERR, "Could not create {$target}.\n");

            return 1;
        }

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

                return 1;
            }

            echo 'Published ' . substr($file, strlen($root) + 1) . "\n";
            $published++;
        }

        echo $published === 0
            ? "Nothing to publish: every Vector migration is already present for the \"{$connection}\" connection.\n"
            : 'Run `vendor/bin/kinetis migrate' . ($connection === 'default' ? '' : " --connection={$connection}") . "` to apply.\n";

        foreach ($this->otherPartitions($migrations, $connection) as $other) {
            echo "Note: Vector's migrations are also published for the \"{$other}\" connection. Vector uses the one VECTOR_DB_CONNECTION names.\n";
        }

        $configured = trim($config->string('VECTOR_DB_CONNECTION', ''));

        if (($configured === '' ? 'default' : $configured) !== $connection) {
            echo $connection === 'default'
                ? "Remove VECTOR_DB_CONNECTION (currently \"{$configured}\") so Vector uses the default connection.\n"
                : "Set VECTOR_DB_CONNECTION={$connection} in the application's configuration so Vector uses these tables.\n";
        }

        return $this->publishUi(dirname(__DIR__, 2) . '/resources/dist', $root . '/public' . VectorUi::PATH);
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
     * Copies the built admin bundle into the web root, replacing what an
     * earlier install left there, so the shell always references files
     * that exist.
     */
    private function publishUi(string $dist, string $target): int
    {
        if (!is_file($dist . '/index.html')) {
            echo "No built UI in {$dist}; the hosted admin stays unpublished (build it with `pnpm generate:hosted`).\n";

            return 0;
        }

        $files = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dist, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $item) {
            $relative = substr((string) $item->getPathname(), strlen($dist) + 1);
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
