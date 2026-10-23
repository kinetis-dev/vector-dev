<?php

declare(strict_types=1);

namespace App\Tests\Vector;

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
 * command touches files only, in a temporary project root.
 */
final class InstallCommandTest extends TestCase
{
    private string $root;

    private mixed $binDir;

    private string|false $connection;

    private string|false $uiDir;

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
    }

    protected function tearDown(): void
    {
        $GLOBALS['_composer_bin_dir'] = $this->binDir;
        putenv($this->connection === false ? 'DB_CONNECTION' : 'DB_CONNECTION=' . $this->connection);
        putenv($this->uiDir === false ? 'VECTOR_UI_DIR' : 'VECTOR_UI_DIR=' . $this->uiDir);

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
     * @return array{int, string}
     */
    private function install(): array
    {
        ob_start();
        $status = new InstallCommand()->install(CommandArguments::parse([]));

        return [$status, (string) ob_get_clean()];
    }

    public function testAFreshApplicationGetsItsMigrationsDirectoryCreated(): void
    {
        self::assertDirectoryDoesNotExist($this->root . '/migrations');

        [$status] = $this->install();

        self::assertSame(0, $status);
        self::assertNotSame([], glob($this->root . '/migrations/*_vector_*.php'));
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
