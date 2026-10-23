<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Ui\HostedBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HostedBundle::verify(): a directory holds the @kinetis/vector-ui bundle
 * its vector-bundle.json describes — this package, this descriptor
 * contract, the expected version when one is given, and every listed
 * file with its listed hash. Each refusal is one line naming the cause
 * and `vector:install-ui` as the remedy. No database: files only.
 */
final class HostedBundleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = UiFixtures::tempDir('bundle');
    }

    protected function tearDown(): void
    {
        UiFixtures::remove($this->dir);
    }

    public function testABundleOfTheExpectedVersionVerifies(): void
    {
        UiFixtures::bundle($this->dir, '0.11.0');

        $bundle = HostedBundle::verify($this->dir, '0.11.0');

        self::assertSame('@kinetis/vector-ui', $bundle->package);
        self::assertSame('0.11.0', $bundle->version);
        self::assertSame(VectorRegistry::VERSION, $bundle->descriptorVersion);
        self::assertSame(UiFixtures::SOURCE, $bundle->source);
        $files = array_keys(UiFixtures::files('0.11.0'));
        sort($files);
        self::assertSame($files, array_keys($bundle->files));
    }

    public function testNoExpectedVersionAcceptsAny(): void
    {
        UiFixtures::bundle($this->dir, 'dev-0123456789ab');

        self::assertSame('dev-0123456789ab', HostedBundle::verify($this->dir, null)->version);
    }

    public function testFilesTheManifestDoesNotListAreNotItsConcern(): void
    {
        // the served directory may hold the deployment's own files too
        UiFixtures::bundle($this->dir, '0.11.0');
        file_put_contents($this->dir . '/robots.txt', 'User-agent: *');

        self::assertSame('0.11.0', HostedBundle::verify($this->dir, '0.11.0')->version);
    }

    public function testVersionsCompareAsPlainStrings(): void
    {
        UiFixtures::bundle($this->dir, '0.11.0');

        $this->expectExceptionMessage('it is version 0.11.0; 0.11.00 is expected');
        HostedBundle::verify($this->dir, '0.11.00');
    }

    /**
     * @return iterable<string, array{\Closure(string): void, ?string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'a version mismatch' => [static fn (string $dir) => UiFixtures::bundle($dir, '0.10.0'), '0.11.0', 'it is version 0.10.0; 0.11.0 is expected'];

        yield 'a dev build where a release is expected' => [static fn (string $dir) => UiFixtures::bundle($dir, 'dev-0123456789ab'), '0.11.0', 'it is version dev-0123456789ab; 0.11.0 is expected'];

        yield 'no manifest' => [static function (string $dir): void {
            file_put_contents("{$dir}/index.html", '<!doctype html>');
        }, '0.11.0', 'it has no vector-bundle.json'];

        yield 'another package' => [static fn (string $dir) => UiFixtures::bundle($dir, '0.11.0', [], ['package' => '@acme/vector-ui']), '0.11.0', 'it was built for @acme/vector-ui, not @kinetis/vector-ui'];

        yield 'the Composer package' => [static fn (string $dir) => UiFixtures::bundle($dir, '0.11.0', [], ['package' => 'kinetis/vector']), null, 'it was built for kinetis/vector, not @kinetis/vector-ui'];

        yield 'another descriptor version' => [static fn (string $dir) => UiFixtures::bundle($dir, '0.11.0', [], ['descriptorVersion' => VectorRegistry::VERSION + 1]), null, 'it speaks descriptor version ' . (VectorRegistry::VERSION + 1)];

        yield 'manifest is not JSON' => [static function (string $dir): void {
            file_put_contents("{$dir}/" . HostedBundle::MANIFEST, '{"package":');
        }, null, 'vector-bundle.json is malformed'];

        yield 'source is not a commit' => [static fn (string $dir) => UiFixtures::bundle($dir, '0.11.0', [], ['source' => 'main']), null, 'vector-bundle.json is malformed'];

        yield 'version is neither SemVer nor dev-<sha>' => [static fn (string $dir) => UiFixtures::bundle($dir, 'v0.11.0'), null, 'vector-bundle.json is malformed'];

        yield 'a listed path leaves the bundle' => [static fn (string $dir) => UiFixtures::bundle($dir, '0.11.0', [], ['files' => ['../index.html' => str_repeat('0', 64)]]), null, 'vector-bundle.json is malformed'];

        yield 'a listed path is absolute' => [static fn (string $dir) => UiFixtures::bundle($dir, '0.11.0', [], ['files' => ['/etc/passwd' => str_repeat('0', 64)]]), null, 'vector-bundle.json is malformed'];

        yield 'the manifest lists itself' => [static fn (string $dir) => UiFixtures::bundle($dir, '0.11.0', [], ['files' => [HostedBundle::MANIFEST => str_repeat('0', 64)]]), null, 'vector-bundle.json is malformed'];

        yield 'the manifest lists no shell' => [static fn (string $dir) => UiFixtures::bundle($dir, '0.11.0', ['app.js' => '1']), null, 'vector-bundle.json lists no index.html'];

        yield 'a listed file is missing' => [static function (string $dir): void {
            UiFixtures::bundle($dir, '0.11.0');
            unlink("{$dir}/_nuxt/shared.css");
        }, '0.11.0', '_nuxt/shared.css is listed in vector-bundle.json but missing'];

        yield 'a file changed after the build' => [static function (string $dir): void {
            UiFixtures::bundle($dir, '0.11.0');
            file_put_contents("{$dir}/_nuxt/shared.css", 'body{color:red}');
        }, '0.11.0', '_nuxt/shared.css changed after vector-bundle.json was written'];
    }

    /**
     * @param \Closure(string): void $arrange
     */
    #[DataProvider('refusals')]
    public function testARefusedBundleNamesTheCauseAndTheRemedy(\Closure $arrange, ?string $expected, string $cause): void
    {
        $arrange($this->dir);

        try {
            HostedBundle::verify($this->dir, $expected);
            self::fail('The bundle verified.');
        } catch (\UnexpectedValueException $e) {
            self::assertStringStartsWith("The admin UI bundle in {$this->dir} does not verify: {$cause}", $e->getMessage());
            self::assertStringEndsWith('Run `vendor/bin/kinetis vector:install-ui` to install a verified one.', $e->getMessage());
            self::assertStringNotContainsString("\n", $e->getMessage());
        }
    }

    public function testReadingAManifestHashesNothing(): void
    {
        UiFixtures::bundle($this->dir, '0.11.0');
        file_put_contents("{$this->dir}/_nuxt/shared.css", 'changed');

        self::assertSame('0.11.0', HostedBundle::read($this->dir . '/' . HostedBundle::MANIFEST)?->version);
        self::assertNull(HostedBundle::read($this->dir . '/missing.json'));
    }
}
