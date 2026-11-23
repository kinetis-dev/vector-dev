<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Console;

use Kinetis\Config\Config;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Vector\Database\VectorDatabase;

/**
 * Publishes kinetis/vector-ai's migrations into the application, the
 * sealed provider keys (`vector_ai_credentials`), the usage ledger
 * (`vector_ai_usage`), the assistant's proposals (`vector_ai_approvals`)
 * and the bulk rewrites' rows (`vector_ai_rewrites`), the way
 * `vector:install` publishes Vector's: into
 * `migrations/` for the default connection or `migrations/<name>/` for a
 * named one, in the dialect `DB_CONNECTION` or `DB_<NAME>_CONNECTION`
 * names. Put them on the connection Vector's own tables are on
 * (VECTOR_DB_CONNECTION). Nothing is overwritten; run `kinetis migrate`
 * afterwards. No bootstrap: this touches files only.
 *
 *     kinetis vector-ai:install                     on the default connection
 *     kinetis vector-ai:install --connection=admin  on the named connection "admin"
 */
final readonly class InstallCommand
{
    /** The package's own migration stubs, `<version>_<name>.<dialect>.stub`. */
    private const string STUBS = __DIR__ . '/../../resources/migrations';

    public function __construct(private Output $output) {}

    #[Command('vector-ai:install', description: "Publishes kinetis/vector-ai's migrations. --connection=<name> for Vector's connection when it is not the default.", bootstrap: false)]
    public function install(CommandArguments $arguments): int
    {
        return $this->run($arguments, Config::fromEnvironment(), ProjectRoot::detect(dirname(__DIR__)));
    }

    /**
     * The command, with the configuration, the project root and the stubs given rather than read.
     *
     * @internal public so tests can run it on a fixture project; install() is the command
     */
    public function run(CommandArguments $arguments, Config $config, string $root, ?string $stubDirectory = null): int
    {
        $connection = $this->connection($arguments);

        if ($connection === null) {
            return 2;
        }

        $dialect = $this->dialect($config, $connection);

        if ($dialect === null) {
            return 1;
        }

        $stubs = glob(($stubDirectory ?? self::STUBS) . "/*.{$dialect}.stub") ?: [];

        if ($stubs === []) {
            // "already present" would hide a package that ships none
            $this->output->error("kinetis/vector-ai ships no {$dialect} migrations; reinstall the package.");

            return 1;
        }

        $target = $root . '/migrations' . ($connection === 'default' ? '' : "/{$connection}");

        if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
            $this->output->error("Could not create {$target}.");

            return 1;
        }

        $published = $this->publish($stubs, $target, $dialect, $root);

        if ($published === null) {
            return 1;
        }

        $this->notes($config, $connection, $published);

        return 0;
    }

    /** The connection --connection names, `default` without it; null, the refusal written, when it names none or an invalid one. */
    private function connection(CommandArguments $arguments): ?string
    {
        try {
            return VectorDatabase::validated(
                $arguments->hasOption('connection')
                    ? ($arguments->option('connection') ?? throw new \InvalidArgumentException('--connection needs a value: --connection=<name>.'))
                    : 'default',
                '--connection',
            );
        } catch (\InvalidArgumentException $e) {
            $this->output->error($e->getMessage());

            return null;
        }
    }

    /** The dialect $connection is configured with; null, the refusal written, when it is unset or not one this package ships. */
    private function dialect(Config $config, string $connection): ?string
    {
        $key = Config::scopedKey('DB_CONNECTION', $connection);
        $dialect = $config->get($key);

        if (!in_array($dialect, ['mysql', 'pgsql'], true)) {
            $this->output->error($dialect === null
                ? "{$key} is not set, so the \"{$connection}\" connection's dialect is unknown. Configure the connection first."
                : "{$key} is \"{$dialect}\"; kinetis/vector-ai supports mysql and pgsql.");

            return null;
        }

        return $dialect;
    }

    /**
     * Writes each stub not yet in $target, saying so; how many it wrote,
     * or null, the refusal written, when one could not be.
     *
     * @param list<string> $stubs
     */
    private function publish(array $stubs, string $target, string $dialect, string $root): ?int
    {
        $published = 0;

        foreach ($stubs as $stub) {
            $file = $target . '/' . basename($stub, ".{$dialect}.stub") . '.php';

            if (file_exists($file)) {
                continue;
            }

            $contents = file_get_contents($stub);

            if ($contents === false || file_put_contents($file, $contents) === false) {
                $this->output->error("Could not write {$file}.");

                return null;
            }

            $this->output->line('Published ' . substr($file, strlen($root) + 1));
            $published++;
        }

        return $published;
    }

    /** What to do next, and where Vector's own tables are when they are on another connection. */
    private function notes(Config $config, string $connection, int $published): void
    {
        $option = $connection === 'default' ? '' : " --connection={$connection}";
        $this->output->line($published === 0
            ? "Nothing to publish: every kinetis/vector-ai migration is already present for the \"{$connection}\" connection."
            : "Run `vendor/bin/kinetis migrate{$option}` to apply.");

        $vector = trim($config->string('VECTOR_DB_CONNECTION', ''));

        if (($vector === '' ? 'default' : $vector) !== $connection) {
            $this->output->line("Note: Vector's own tables are on the \"" . ($vector === '' ? 'default' : $vector) . "\" connection (VECTOR_DB_CONNECTION); kinetis/vector-ai's tables belong beside them.");
        }
    }
}
