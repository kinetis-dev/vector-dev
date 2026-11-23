<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Tests\Fixtures\CommandOutput;
use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
use Kinetis\VectorAi\Console\InstallCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * vector-ai:install publishes kinetis/vector-ai's migrations (exactly the
 * ones named here, so a stub that goes missing or is renamed fails rather
 * than passing as "already present") into migrations/ for the default
 * connection or migrations/<name>/ for a named one, and a second run
 * writes nothing. A package that ships no stubs at all is an error. No
 * database: the command touches files only, in a temporary project root.
 */
final class InstallCommandTest extends TestCase
{
    /** The migrations kinetis/vector-ai ships, for each dialect. */
    private const array MIGRATIONS = [
        '20261119000101_vector_ai_credentials',
        '20261119000102_vector_ai_usage',
        '20261120000101_vector_ai_approvals',
        '20261121000101_vector_ai_rewrites',
    ];

    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/vector-ai-install-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    #[\Override]
    protected function tearDown(): void
    {
        self::remove($this->root);
    }

    /** @return iterable<string, array{list<string>, array<string, string>, string, string}> */
    public static function connections(): iterable
    {
        yield 'the default connection on mysql' => [[], ['DB_CONNECTION' => 'mysql'], 'migrations', 'mysql'];
        yield 'a named connection on pgsql' => [['--connection=admin'], [Config::scopedKey('DB_CONNECTION', 'admin') => 'pgsql', 'VECTOR_DB_CONNECTION' => 'admin'], 'migrations/admin', 'pgsql'];
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     */
    #[DataProvider('connections')]
    public function testItPublishesEveryMigrationOnceForTheConnection(array $argv, array $env, string $directory, string $dialect): void
    {
        [$status, $output, $errors] = $this->install($argv, $env);

        $published = '';

        foreach (self::MIGRATIONS as $migration) {
            $published .= "Published {$directory}/{$migration}.php\n";
        }

        self::assertSame(0, $status);
        self::assertSame('', $errors);
        self::assertSame($published . 'Run `vendor/bin/kinetis migrate' . ($argv === [] ? '' : ' --connection=admin') . "` to apply.\n", $output);
        self::assertSame(self::files(self::MIGRATIONS, '.php'), self::listing("{$this->root}/{$directory}"));

        foreach (self::MIGRATIONS as $migration) {
            $publishedFile = "{$this->root}/{$directory}/{$migration}.php";
            self::assertFileEquals(self::stubs() . "/{$migration}.{$dialect}.stub", $publishedFile);
        }

        // a second run writes nothing, and says so
        $contents = fn (): array => array_map(fn (string $file): string|false => file_get_contents("{$this->root}/{$directory}/{$file}"), self::listing("{$this->root}/{$directory}"));
        $before = $contents();
        [$status, $output, $errors] = $this->install($argv, $env);

        self::assertSame(0, $status);
        self::assertSame('', $errors);
        self::assertSame('Nothing to publish: every kinetis/vector-ai migration is already present for the "' . ($argv === [] ? 'default' : 'admin') . "\" connection.\n", $output);
        self::assertSame($before, $contents());
    }

    public function testThePackageShipsExactlyTheseStubs(): void
    {
        $expected = [...self::files(self::MIGRATIONS, '.mysql.stub'), ...self::files(self::MIGRATIONS, '.pgsql.stub')];
        sort($expected);

        self::assertSame($expected, self::listing(self::stubs(), '*.stub'));
    }

    public function testNoStubsAtAllIsAnErrorNotAlreadyPresent(): void
    {
        mkdir($this->root . '/empty-stubs');

        $capture = new CommandOutput();
        $status = new InstallCommand($capture->output)->run(CommandArguments::parse([]), new Config(['DB_CONNECTION' => 'mysql']), $this->root, $this->root . '/empty-stubs');

        self::assertSame(1, $status);
        self::assertSame('', $capture->stdout());
        self::assertSame("kinetis/vector-ai ships no mysql migrations; reinstall the package.\n", $capture->stderr());
        self::assertDirectoryDoesNotExist($this->root . '/migrations');
    }

    public function testAConnectionOtherThanVectorsIsNoted(): void
    {
        [$status, $output] = $this->install(['--connection=admin'], [Config::scopedKey('DB_CONNECTION', 'admin') => 'mysql']);

        self::assertSame(0, $status);
        self::assertStringEndsWith("Note: Vector's own tables are on the \"default\" connection (VECTOR_DB_CONNECTION); kinetis/vector-ai's tables belong beside them.\n", $output);
    }

    /** @return iterable<string, array{list<string>, array<string, string>, int, string}> */
    public static function refusals(): iterable
    {
        yield '--connection without a value' => [['--connection'], ['DB_CONNECTION' => 'mysql'], 2, "--connection needs a value: --connection=<name>.\n"];
        yield 'an invalid connection name' => [['--connection=Admin'], ['DB_CONNECTION' => 'mysql'], 2, "Invalid connection name \"Admin\" from --connection: a connection name is lowercase ASCII letters and digits, starting with a letter.\n"];
        yield 'the reserved connection name' => [['--connection=app'], ['DB_CONNECTION' => 'mysql'], 2, "The connection name \"app\" from --connection is reserved: its DB_NAME key, DB_APP_NAME, is also the default connection's DB_APP_NAME.\n"];
        yield 'no dialect' => [[], [], 1, "DB_CONNECTION is not set, so the \"default\" connection's dialect is unknown. Configure the connection first.\n"];
        yield 'no dialect for a named connection' => [['--connection=admin'], ['DB_CONNECTION' => 'mysql'], 1, 'DB_ADMIN_CONNECTION is not set, so the "admin" connection\'s dialect is unknown. Configure the connection first.' . "\n"];
        yield 'an unsupported dialect' => [[], ['DB_CONNECTION' => 'sqlite'], 1, "DB_CONNECTION is \"sqlite\"; kinetis/vector-ai supports mysql and pgsql.\n"];
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     */
    #[DataProvider('refusals')]
    public function testARefusalWritesNothingAndExitsNonZero(array $argv, array $env, int $exit, string $error): void
    {
        [$status, $output, $errors] = $this->install($argv, $env);

        self::assertSame($exit, $status);
        self::assertSame('', $output);
        self::assertSame($error, $errors);
        self::assertDirectoryDoesNotExist($this->root . '/migrations');
    }

    public function testATargetThatCannotBeCreatedIsAnError(): void
    {
        // a file where the directory would go
        file_put_contents($this->root . '/migrations', '');

        [$status, $output, $errors] = @$this->install([], ['DB_CONNECTION' => 'mysql']);

        self::assertSame(1, $status);
        self::assertSame('', $output);
        self::assertSame("Could not create {$this->root}/migrations.\n", $errors);
    }

    public function testAMigrationThatCannotBeWrittenStopsThePublishing(): void
    {
        mkdir($this->root . '/migrations');
        $first = self::MIGRATIONS[0];
        // a dangling link where the first migration's file would go: it is not there, and cannot be written
        symlink($this->root . '/missing/' . $first . '.php', "{$this->root}/migrations/{$first}.php");

        [$status, $output, $errors] = @$this->install([], ['DB_CONNECTION' => 'mysql']);

        self::assertSame(1, $status);
        self::assertSame('', $output);
        self::assertSame("Could not write {$this->root}/migrations/{$first}.php.\n", $errors);
        self::assertSame([$first . '.php'], self::listing($this->root . '/migrations'));
    }

    public function testOnlyTheMissingMigrationsArePublished(): void
    {
        mkdir($this->root . '/migrations');
        file_put_contents($this->root . '/migrations/' . self::MIGRATIONS[1] . '.php', 'kept');

        [$status, $output, $errors] = $this->install([], ['DB_CONNECTION' => 'mysql']);

        self::assertSame(0, $status);
        self::assertSame('', $errors);
        self::assertSame(
            'Published migrations/' . self::MIGRATIONS[0] . ".php\n"
            . 'Published migrations/' . self::MIGRATIONS[2] . ".php\n"
            . 'Published migrations/' . self::MIGRATIONS[3] . ".php\n"
            . "Run `vendor/bin/kinetis migrate` to apply.\n",
            $output,
        );
        self::assertStringEqualsFile($this->root . '/migrations/' . self::MIGRATIONS[1] . '.php', 'kept');
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     * @return array{int, string, string} the exit code, stdout and stderr
     */
    private function install(array $argv, array $env): array
    {
        $capture = new CommandOutput();
        $status = new InstallCommand($capture->output)->run(CommandArguments::parse($argv), new Config($env), $this->root);

        return [$status, $capture->stdout(), $capture->stderr()];
    }

    /** The directory the installed command reads its stubs from. */
    private static function stubs(): string
    {
        return dirname((string) new \ReflectionClass(InstallCommand::class)->getFileName(), 3) . '/resources/migrations';
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function files(array $names, string $suffix): array
    {
        return array_map(static fn (string $name): string => $name . $suffix, $names);
    }

    /** @return list<string> the names in $directory matching $pattern, sorted */
    private static function listing(string $directory, string $pattern = '*'): array
    {
        $names = array_map(basename(...), glob("{$directory}/{$pattern}") ?: []);
        sort($names);

        return $names;
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove("{$path}/{$entry}");
                }
            }

            rmdir($path);

            return;
        }

        unlink($path);
    }
}
