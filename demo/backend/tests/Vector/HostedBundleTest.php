<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Console\CommandArguments;
use Kinetis\Vector\Console\InstallCommand;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Ui\HostedBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * vector:install publishing the hosted admin bundle: a bundle is copied
 * only when it matches its vector-bundle.json, so a stale build — one
 * another version built, or one whose files changed after the manifest
 * was written — exits 1, publishes no migration and copies nothing.
 * Fixture bundles in a temporary project root, installed through
 * InstallCommand::installFrom with the installed release the command
 * would otherwise read from Composer. No database: the command touches
 * files only.
 */
final class HostedBundleTest extends TestCase
{
    private const string SOURCE = '0123456789abcdef0123456789abcdef01234567';

    private string $root;

    private string $dist;

    private string $target;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $stderr;

    /** @var resource|null */
    private $filter = null;

    private mixed $binDir;

    private string|false $connection;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/vector-bundle-' . bin2hex(random_bytes(6));
        $this->binDir = $GLOBALS['_composer_bin_dir'] ?? null;
        $this->connection = getenv('DB_CONNECTION');
        $GLOBALS['_composer_bin_dir'] = $this->root . '/vendor/bin';
        putenv('DB_CONNECTION=mysql');
        $this->dist = $this->root . '/dist';
        $this->target = $this->root . '/public/vector/ui';
        mkdir($this->dist . '/_nuxt', 0755, true);
        file_put_contents($this->dist . '/index.html', '<!doctype html><title>Vector</title>');
        file_put_contents($this->dist . '/_nuxt/entry.js', 'console.log(1)');
        file_put_contents($this->dist . '/_nuxt/entry.css', 'body{}');

        $this->stderr = new \ArrayObject();
        if (!in_array('vector.capture', stream_get_filters(), true)) {
            stream_filter_register('vector.capture', CapturingFilter::class);
        }

        $this->filter = stream_filter_append(STDERR, 'vector.capture', STREAM_FILTER_WRITE, $this->stderr) ?: null;
    }

    protected function tearDown(): void
    {
        if ($this->filter !== null) {
            stream_filter_remove($this->filter);
        }

        $GLOBALS['_composer_bin_dir'] = $this->binDir;
        putenv($this->connection === false ? 'DB_CONNECTION' : 'DB_CONNECTION=' . $this->connection);

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

        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Writes the manifest the build script writes for the files now in
     * dist/, with $extraFiles listed too and $overrides applied on top.
     *
     * @param array<string, mixed> $overrides
     * @param array<string, string> $extraFiles
     */
    private function writeManifest(array $overrides = [], array $extraFiles = []): void
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dist, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $item) {
            $relative = substr((string) $item->getPathname(), strlen($this->dist) + 1);

            if ($relative !== HostedBundle::MANIFEST) {
                $files[$relative] = (string) hash_file('sha256', (string) $item->getPathname());
            }
        }

        $files = [...$files, ...$extraFiles];
        ksort($files, SORT_STRING);

        file_put_contents($this->dist . '/' . HostedBundle::MANIFEST, json_encode([
            'package' => 'kinetis/vector',
            'version' => '0.9.0',
            'descriptorVersion' => VectorRegistry::VERSION,
            'source' => self::SOURCE,
            'files' => $files,
            ...$overrides,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{int, string, string}
     */
    private function publish(?string $release): array
    {
        ob_start();
        $status = new InstallCommand()->installFrom(CommandArguments::parse([]), $this->dist, $release);
        $output = (string) ob_get_clean();

        return [$status, $output, implode('', $this->stderr->getArrayCopy())];
    }

    public function testAnAbsentBundleIsASourceInstallNotAnError(): void
    {
        self::remove($this->dist);

        [$status, $output, $stderr] = $this->publish('0.9.0');

        self::assertSame(0, $status);
        self::assertStringContainsString('No hosted admin bundle', $output);
        self::assertSame('', $stderr);
        self::assertDirectoryDoesNotExist($this->target);
        self::assertNotSame([], glob($this->root . '/migrations/*_vector_*.php'));
    }

    public function testADistWithNeitherShellNorManifestIsStillAbsent(): void
    {
        unlink($this->dist . '/index.html');

        [$status, $output, $stderr] = $this->publish(null);

        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('No hosted admin bundle', $output);
        self::assertDirectoryDoesNotExist($this->target);
    }

    public function testAReleaseBundleForTheInstalledReleaseIsPublishedWithoutItsManifest(): void
    {
        $this->writeManifest();

        [$status, $output, $stderr] = $this->publish('0.9.0');

        self::assertSame(0, $status, $stderr);
        self::assertNotSame([], glob($this->root . '/migrations/*_vector_*.php'));
        self::assertStringContainsString('Published the admin UI (3 files)', $output);
        self::assertStringNotContainsString('development build', $output);
        self::assertFileEquals($this->dist . '/index.html', $this->target . '/index.html');
        self::assertFileEquals($this->dist . '/_nuxt/entry.js', $this->target . '/_nuxt/entry.js');
        self::assertFileDoesNotExist($this->target . '/' . HostedBundle::MANIFEST);
    }

    public function testADevBundleIsPublishedNamingItsBuild(): void
    {
        $this->writeManifest(['version' => 'dev-0123456789ab']);

        [$status, $output, $stderr] = $this->publish(null);

        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('Publishing a development build of the admin UI: version dev-0123456789ab, built from ' . self::SOURCE . ".\n", $output);
        self::assertFileEquals($this->dist . '/index.html', $this->target . '/index.html');
    }

    /**
     * Every refusal: exit 1, one line on STDERR naming the cause, and
     * nothing copied.
     *
     * @return iterable<string, array{\Closure(self): void, ?string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'no manifest' => [static function (self $test): void {}, null, 'it has no vector-bundle.json'];

        yield 'manifest is not JSON' => [static function (self $test): void {
            file_put_contents($test->dist . '/' . HostedBundle::MANIFEST, '{"package":');
        }, null, 'vector-bundle.json is malformed'];

        yield 'source is not a commit' => [static fn (self $test) => $test->writeManifest(['source' => 'main']), null, 'vector-bundle.json is malformed'];

        yield 'version is neither SemVer nor dev-<sha>' => [static fn (self $test) => $test->writeManifest(['version' => 'v0.9.0']), null, 'vector-bundle.json is malformed'];

        yield 'files is not an object' => [static fn (self $test) => $test->writeManifest(['files' => 'index.html']), null, 'vector-bundle.json is malformed'];

        yield 'a listed path leaves the bundle' => [static fn (self $test) => $test->writeManifest(['files' => ['../index.html' => str_repeat('0', 64)]]), null, 'vector-bundle.json is malformed'];

        yield 'a listed path is absolute' => [static fn (self $test) => $test->writeManifest([], ['/etc/passwd' => str_repeat('0', 64)]), null, 'vector-bundle.json is malformed'];

        // the target exists and its hash is right, so only the path check refuses it
        yield 'a listed path climbs out midway' => [static function (self $test): void {
            file_put_contents($test->root . '/x', 'outside');
            $test->writeManifest([], ['_nuxt/../../x' => hash('sha256', 'outside')]);
        }, null, 'vector-bundle.json is malformed'];

        yield 'the manifest lists no shell' => [static function (self $test): void {
            unlink($test->dist . '/index.html');
            $test->writeManifest();
        }, null, 'vector-bundle.json lists no index.html'];

        yield 'a manifest without its shell' => [static function (self $test): void {
            $test->writeManifest();
            unlink($test->dist . '/index.html');
        }, '0.9.0', 'index.html is listed in vector-bundle.json but missing'];

        yield 'another package' => [static fn (self $test) => $test->writeManifest(['package' => 'acme/vector']), null, 'it was built for acme/vector, not kinetis/vector'];

        yield 'another descriptor version' => [static fn (self $test) => $test->writeManifest(['descriptorVersion' => VectorRegistry::VERSION + 1]), null, 'it speaks descriptor version ' . (VectorRegistry::VERSION + 1)];

        yield 'a stale build for another release' => [static fn (self $test) => $test->writeManifest(['version' => '0.8.0']), '0.9.0', 'it was built for version 0.8.0; kinetis/vector 0.9.0 is installed'];

        yield 'a dev build in a release' => [static fn (self $test) => $test->writeManifest(['version' => 'dev-0123456789ab']), '0.9.0', 'it was built for version dev-0123456789ab'];

        yield 'a listed file is missing' => [static function (self $test): void {
            $test->writeManifest();
            unlink($test->dist . '/_nuxt/entry.js');
        }, null, '_nuxt/entry.js is listed in vector-bundle.json but missing'];

        yield 'a file changed after the build' => [static function (self $test): void {
            $test->writeManifest();
            file_put_contents($test->dist . '/_nuxt/entry.js', 'console.log(2)');
        }, '0.9.0', '_nuxt/entry.js changed after vector-bundle.json was written'];

        yield 'a file the build did not write' => [static function (self $test): void {
            $test->writeManifest();
            file_put_contents($test->dist . '/_nuxt/stale.js', 'old');
        }, null, '_nuxt/stale.js is not in vector-bundle.json'];
    }

    /**
     * @param \Closure(self): void $arrange
     */
    #[DataProvider('refusals')]
    public function testARefusedBundleExitsNonZeroAndPublishesNothing(\Closure $arrange, ?string $release, string $cause): void
    {
        $arrange($this);

        [$status, $output, $stderr] = $this->publish($release);

        self::assertSame(1, $status);
        self::assertSame('', $output);
        self::assertStringStartsWith("Refusing to publish the admin bundle in {$this->dist}: {$cause}", $stderr);
        self::assertStringEndsWith($release === null ? "Rebuild it with `pnpm generate:hosted`, or delete it.\n" : "Reinstall kinetis/vector to restore the bundle the release shipped.\n", $stderr);
        self::assertSame(1, substr_count($stderr, "\n"));
        self::assertDirectoryDoesNotExist($this->target);
        self::assertDirectoryDoesNotExist($this->root . '/migrations');
    }

    public function testARefusedBundleStopsTheMigrationsOnANamedConnectionToo(): void
    {
        $admin = getenv('DB_ADMIN_CONNECTION');
        putenv('DB_ADMIN_CONNECTION=pgsql');

        try {
            $this->writeManifest(['version' => '0.8.0']);

            ob_start();
            $status = new InstallCommand()->installFrom(CommandArguments::parse(['--connection=admin']), $this->dist, '0.9.0');
            $output = (string) ob_get_clean();
        } finally {
            putenv($admin === false ? 'DB_ADMIN_CONNECTION' : 'DB_ADMIN_CONNECTION=' . $admin);
        }

        self::assertSame(1, $status);
        self::assertSame('', $output);
        self::assertDirectoryDoesNotExist($this->root . '/migrations');
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

    #[DataProvider('installedVersions')]
    public function testTheInstalledVersionNamesTheReleaseABundleMustMatch(?string $prettyVersion, ?string $release): void
    {
        self::assertSame($release, InstallCommand::release($prettyVersion));
    }

    public function testVerificationReportsTheBuildItAccepted(): void
    {
        $this->writeManifest();

        $bundle = HostedBundle::verify($this->dist, '0.9.0');

        self::assertSame('0.9.0', $bundle->version);
        self::assertSame(self::SOURCE, $bundle->source);
        self::assertSame(['_nuxt/entry.css', '_nuxt/entry.js', 'index.html'], array_keys($bundle->files));
    }
}

/**
 * Records what is written to the stream it is appended to, in the
 * ArrayObject passed as its params, and passes nothing on.
 */
final class CapturingFilter extends \php_user_filter
{
    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     */
    #[\Override]
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            if ($this->params instanceof \ArrayObject) {
                $this->params->append($bucket->data);
            }

            $consumed += $bucket->datalen;
        }

        return PSFS_PASS_ON;
    }
}
