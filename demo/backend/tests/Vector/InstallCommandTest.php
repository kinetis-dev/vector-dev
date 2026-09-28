<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Console\CommandArguments;
use Kinetis\Vector\Console\InstallCommand;
use PHPUnit\Framework\TestCase;

/**
 * vector:install on a fresh application: no migrations/ directory yet.
 * The command creates it and publishes into it rather than refusing. No
 * database: the command touches files only, in a temporary project root.
 */
final class InstallCommandTest extends TestCase
{
    private string $root;

    private mixed $binDir;

    private string|false $connection;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/vector-install-' . bin2hex(random_bytes(6));
        mkdir($this->root);
        $this->binDir = $GLOBALS['_composer_bin_dir'] ?? null;
        $this->connection = getenv('DB_CONNECTION');
        $GLOBALS['_composer_bin_dir'] = $this->root . '/vendor/bin';
        putenv('DB_CONNECTION=mysql');
    }

    protected function tearDown(): void
    {
        $GLOBALS['_composer_bin_dir'] = $this->binDir;
        putenv($this->connection === false ? 'DB_CONNECTION' : 'DB_CONNECTION=' . $this->connection);

        // the whole temporary project: migrations/, and public/vector/ui/ when a hosted bundle was there to publish
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

    public function testAFreshApplicationGetsItsMigrationsDirectoryCreated(): void
    {
        self::assertDirectoryDoesNotExist($this->root . '/migrations');

        ob_start();
        $status = new InstallCommand()->install(CommandArguments::parse([]));
        ob_end_clean();

        self::assertSame(0, $status);
        self::assertNotSame([], glob($this->root . '/migrations/*_vector_*.php'));
    }
}
