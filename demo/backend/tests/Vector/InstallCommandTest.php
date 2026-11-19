<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Tests\Fixtures\CommandOutput;
use Kinetis\Console\CommandArguments;
use Kinetis\Vector\Console\InstallCommand;
use Kinetis\Vector\Ui\HostedBundle;
use Kinetis\Vector\Ui\UiPin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * vector:install publishes migrations and nothing else: on a fresh
 * application it creates migrations/ rather than refusing, and it never
 * writes the hosted UI. It notes when the served UI (VECTOR_UI_DIR here)
 * is missing, unversioned or not the pinned release. No database: the
 * command touches files only, in a temporary project root. What it did
 * goes to stdout, a refusal to stderr.
 */
final class InstallCommandTest extends TestCase
{
    private string $root;

    private mixed $binDir;

    private string|false $connection;

    private string|false $uiDir;

    private string|false $vectorConnection;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/vector-install-' . bin2hex(random_bytes(6));
        mkdir($this->root);
        $this->binDir = $GLOBALS['_composer_bin_dir'] ?? null;
        $this->connection = getenv('DB_CONNECTION');
        $GLOBALS['_composer_bin_dir'] = $this->root . '/vendor/bin';
        putenv('DB_CONNECTION=mysql');
        $this->uiDir = getenv('VECTOR_UI_DIR');
        putenv("VECTOR_UI_DIR={$this->root}/ui");
        $this->vectorConnection = getenv('VECTOR_DB_CONNECTION');
        putenv('VECTOR_DB_CONNECTION');
    }

    protected function tearDown(): void
    {
        $GLOBALS['_composer_bin_dir'] = $this->binDir;
        putenv($this->connection === false ? 'DB_CONNECTION' : 'DB_CONNECTION=' . $this->connection);
        putenv($this->uiDir === false ? 'VECTOR_UI_DIR' : 'VECTOR_UI_DIR=' . $this->uiDir);
        putenv($this->vectorConnection === false ? 'VECTOR_DB_CONNECTION' : 'VECTOR_DB_CONNECTION=' . $this->vectorConnection);

        self::remove($this->root);
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

    /**
     * @param list<string> $argv
     * @return array{int, string, string} the exit code, stdout and stderr
     */
    private function install(array $argv = []): array
    {
        $capture = new CommandOutput();
        $status = new InstallCommand($capture->output)->install(CommandArguments::parse($argv));

        return [$status, $capture->stdout(), $capture->stderr()];
    }

    public function testAFreshInstallSaysWhatItPublishedAndWhatToRunNext(): void
    {
        [$status, $output, $errors] = $this->install();

        $expected = '';

        foreach (glob(dirname((string) new \ReflectionClass(InstallCommand::class)->getFileName(), 3) . '/resources/migrations/*.mysql.stub') ?: [] as $stub) {
            $expected .= 'Published migrations/' . basename($stub, '.mysql.stub') . ".php\n";
        }

        $pinned = UiPin::fromFile(UiPin::path())->version;
        $expected .= "Run `vendor/bin/kinetis migrate` to apply.\n"
            . "Note: no admin UI is installed in {$this->root}/ui. Run `vendor/bin/kinetis vector:install-ui` to install @kinetis/vector-ui {$pinned}, then restart workers.\n";

        self::assertSame(0, $status);
        self::assertStringStartsWith('Published migrations/', $expected);
        self::assertSame($expected, $output);
        self::assertSame('', $errors);

        // a second run publishes nothing, and says so
        [$status, $output] = $this->install();
        self::assertSame(0, $status);
        self::assertStringStartsWith("Nothing to publish: every Vector migration is already present for the \"default\" connection.\n", $output);
    }

    /**
     * @return iterable<string, array{list<string>, ?string, int, string}>
     */
    public static function refusals(): iterable
    {
        yield '--connection without a value' => [['--connection'], 'mysql', 2, "--connection needs a value: --connection=<name>.\n"];
        yield 'an unsupported dialect' => [[], 'sqlite', 1, "DB_CONNECTION is \"sqlite\"; Vector supports mysql and pgsql.\n"];
        yield 'no dialect' => [[], null, 1, "DB_CONNECTION is not set, so the \"default\" connection's dialect is unknown. Configure the connection first.\n"];
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('refusals')]
    public function testARefusalGoesToStderrAndPublishesNothing(array $argv, ?string $dialect, int $code, string $message): void
    {
        putenv($dialect === null ? 'DB_CONNECTION' : "DB_CONNECTION={$dialect}");

        [$status, $output, $errors] = $this->install($argv);

        self::assertSame($code, $status);
        self::assertSame('', $output);
        self::assertSame($message, $errors);
        self::assertDirectoryDoesNotExist($this->root . '/migrations');
    }

    public function testAFreshApplicationGetsItsMigrationsDirectoryCreated(): void
    {
        self::assertDirectoryDoesNotExist($this->root . '/migrations');

        [$status] = $this->install();

        self::assertSame(0, $status);
        self::assertNotSame([], glob($this->root . '/migrations/*_vector_*.php'));
    }

    public function testAnotherConnectionHoldingVectorsMigrationsIsNoted(): void
    {
        mkdir($this->root . '/migrations/admin', 0755, true);
        touch($this->root . '/migrations/admin/20260926000005_vector_audit.php');

        [$status, $output] = $this->install();

        self::assertSame(0, $status);
        self::assertStringContainsString("Note: Vector's migrations are also published for the \"admin\" connection.", $output);
    }

    public function testAnotherPackagesMigrationsInAnotherConnectionAreNotVectors(): void
    {
        // kinetis/vector-ai's, beside Vector's tables on another connection: `*_vector_ai_*` is not Vector's own
        mkdir($this->root . '/migrations/admin', 0755, true);
        touch($this->root . '/migrations/admin/20261119000101_vector_ai_credentials.php');
        touch($this->root . '/migrations/admin/20261119000102_vector_ai_usage.php');

        [$status, $output] = $this->install();

        self::assertSame(0, $status);
        self::assertStringNotContainsString('also published', $output);
    }

    public function testOnlyMigrationsArePublished(): void
    {
        [$status] = $this->install();

        self::assertSame(0, $status);
        self::assertDirectoryDoesNotExist($this->root . '/ui');
        self::assertDirectoryDoesNotExist($this->root . '/public');
        self::assertSame(['migrations'], array_values(array_diff(scandir($this->root) ?: [], ['.', '..'])));
    }

    /**
     * @return iterable<string, array{\Closure(string): void, string}>
     */
    public static function drift(): iterable
    {
        yield 'no UI installed' => [static function (string $dir): void {}, 'Note: no admin UI is installed in'];

        yield 'a UI without its manifest' => [static function (string $dir): void {
            mkdir($dir, 0755, true);
            file_put_contents("{$dir}/index.html", '<!doctype html>');
        }, 'has no vector-bundle.json, so its version is unknown'];

        yield 'another version' => [static function (string $dir): void {
            mkdir($dir, 0755, true);
            UiFixtures::bundle($dir, '0.0.1');
        }, 'is @kinetis/vector-ui 0.0.1; this kinetis/vector expects ' . UiPin::fromFile(UiPin::path())->version];
    }

    /**
     * @param \Closure(string): void $arrange
     */
    #[DataProvider('drift')]
    public function testItNotesWhenTheServedUiIsNotThePinnedRelease(\Closure $arrange, string $note): void
    {
        $arrange($this->root . '/ui');

        [$status, $output] = $this->install();

        self::assertSame(0, $status);
        self::assertStringContainsString($note, $output);
        self::assertStringContainsString('Run `vendor/bin/kinetis vector:install-ui` to install @kinetis/vector-ui ' . UiPin::fromFile(UiPin::path())->version . ', then restart workers.', $output);
    }

    public function testItSaysNothingAboutAUiInSync(): void
    {
        mkdir($this->root . '/ui', 0755, true);
        UiFixtures::bundle($this->root . '/ui', UiPin::fromFile(UiPin::path())->version);

        [$status, $output] = $this->install();

        self::assertSame(0, $status);
        self::assertStringNotContainsString('admin UI', $output);
        self::assertFileExists($this->root . '/ui/' . HostedBundle::MANIFEST);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function installedVersions(): iterable
    {
        yield 'a tagged release' => ['v0.9.0', '0.9.0'];
        yield 'a release without the v' => ['0.9.0', '0.9.0'];
        yield 'a pre-release' => ['v1.0.0-rc.1', '1.0.0-rc.1'];
        yield 'a path repository' => ['dev-main', null];
        yield 'a branch alias' => ['1.0.x-dev', null];
        yield 'not installed through Composer' => [null, null];
    }

    /** A null release is a dev install, on which a dev build of the UI is accepted. */
    #[DataProvider('installedVersions')]
    public function testTheInstalledVersionNamesTheReleaseOrADevInstall(?string $prettyVersion, ?string $release): void
    {
        self::assertSame($release, InstallCommand::release($prettyVersion));
    }
}
