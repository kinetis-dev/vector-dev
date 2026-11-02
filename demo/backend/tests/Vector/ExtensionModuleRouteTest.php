<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Vector\Ui\ExtensionModules;
use Kinetis\Vector\Ui\PackageExtensions;
use Kinetis\Vector\Ui\VectorUi;

/**
 * The hosted shell lists the modules installed packages declare before
 * the deployment's VECTOR_UI_EXTENSIONS, and serves them itself from
 * memory, under content-hashed URLs, as immutable JavaScript. Nothing
 * else under the prefix is served: a path is a lookup, never a file.
 */
final class ExtensionModuleRouteTest extends VectorTestCase
{
    use SignsInThroughTheForm;

    private const string FIXTURE = 'acme/vector-queues-fixture';

    /** a repeated entry stays repeated, as before packages could declare modules */
    private const string DEPLOYED = '/vector-extensions/stock-level.js,https://cdn.example.test/ext.js,/vector-extensions/stock-level.js';

    private string $root;

    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'VECTOR_UI_EXTENSIONS' => self::DEPLOYED];
    }

    /** A published shell document, and the fixture package installed in a project of its own. */
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->root = UiFixtures::tempDir('extension-route');
        mkdir($this->root . '/ui');
        file_put_contents($this->root . '/ui/index.html', '<!doctype html><html><head></head><body></body></html>');
        mkdir($this->root . '/vendor/acme', 0755, true);
        symlink(PackageExtensionsTest::fixtureDir(), $this->root . '/vendor/' . self::FIXTURE);
        $manifest = json_decode((string) file_get_contents(PackageExtensionsTest::fixtureDir() . '/composer.json'), true);
        self::assertIsArray($manifest);
        mkdir($this->root . '/vendor/composer');
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => [
            ['name' => self::FIXTURE, 'install-path' => '../' . self::FIXTURE, 'extra' => $manifest['extra']],
        ]], JSON_THROW_ON_ERROR));

        $previous = ini_set('error_log', $this->root . '/error.log'); // the fixture's refused entries
        $app->instance(ExtensionModules::class, ExtensionModules::load(PackageExtensions::discover($this->root), $this->root));
        ini_set('error_log', (string) $previous);
        $app->instance(VectorUi::class, VectorUi::fromConfig(new Config(['VECTOR_UI_DIR' => $this->root . '/ui', 'VECTOR_UI_EXTENSIONS' => $config->string('VECTOR_UI_EXTENSIONS', '')]), $this->root));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        UiFixtures::remove($this->root);
    }

    private static function url(string $entry): string
    {
        return ExtensionModules::PATH . '/' . self::FIXTURE . '/' . PackageExtensionsTest::hashOf($entry) . '/' . basename($entry);
    }

    public function testTheShellListsThePackagesModulesBeforeTheDeployments(): void
    {
        [$cookie] = $this->signIn();
        $shell = $this->client->get('/vector/ui', headers: ['Cookie' => $cookie])->assertOk();
        $config = preg_match('~window\.__VECTOR__ = (\{.*?\});</script>~', $shell->body(), $m) === 1 ? json_decode($m[1], true) : null;
        self::assertIsArray($config, 'the shell carries its configuration');

        self::assertSame([
            self::url('resources/ui/queues.js'),
            self::url('resources/ui/more/panel.mjs'),
            '/vector-extensions/stock-level.js',
            'https://cdn.example.test/ext.js',
            '/vector-extensions/stock-level.js',
        ], $config['extensions']);
    }

    public function testAModuleIsImmutableJavaScriptAnyoneCanLoad(): void
    {
        $module = $this->client->get(self::url('resources/ui/queues.js'))->assertOk();

        self::assertSame((string) file_get_contents(PackageExtensionsTest::fixtureDir() . '/resources/ui/queues.js'), $module->body());
        $module->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
        $module->assertHeader('Cache-Control', 'public, max-age=31536000, immutable');
        $module->assertHeader('X-Content-Type-Options', 'nosniff');
        $module->assertHeader('Content-Length', (string) strlen($module->body()));
        self::assertSame('', $module->getHeaderLine('Set-Cookie'), 'no session is started for a module');

        $this->client->get(self::url('resources/ui/more/panel.mjs'))->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
    }

    /** @return iterable<string, array{string}> */
    public static function unservedPaths(): iterable
    {
        $base = ExtensionModules::PATH . '/' . self::FIXTURE;
        $hash = '{hash}';

        yield 'dot-dot segments' => ["{$base}/{$hash}/../../composer.json"];
        yield 'dot-dot as the file' => ["{$base}/{$hash}/.."];
        yield 'encoded dot-dot' => ["{$base}/{$hash}/%2e%2e%2fcomposer.json"];
        yield 'encoded dot-dot segments' => ["{$base}/%2e%2e/%2e%2e/composer.json"];
        yield 'an encoded absolute path' => [ExtensionModules::PATH . '/%2Fetc/passwd/x/y'];
        yield 'an absolute path after the prefix' => [ExtensionModules::PATH . '//etc/passwd'];
        yield 'an absolute path as the file' => ["{$base}/{$hash}/%2Fetc%2Fpasswd"];
        yield 'an undeclared file of the package' => ["{$base}/{$hash}/README.md"];
        yield 'a refused hidden file' => ["{$base}/{$hash}/.hidden.js"];
        yield 'an unregistered name' => ["{$base}/{$hash}/nothing.js"];
        yield 'an unregistered package' => [ExtensionModules::PATH . "/acme/other/{$hash}/queues.js"];
        yield 'another hash' => ["{$base}/0000000000000000/queues.js"];
        yield 'the directory' => ["{$base}/{$hash}/"];
        yield 'the package' => ["{$base}"];
        yield 'the prefix' => [ExtensionModules::PATH];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unservedPaths')]
    public function testNothingButADeclaredModuleIsServed(string $path): void
    {
        $path = str_replace('{hash}', PackageExtensionsTest::hashOf('resources/ui/queues.js'), $path);
        $response = $this->client->get($path)->assertStatus(404);

        self::assertStringNotContainsString('definePage', $response->body());
        self::assertStringNotContainsString('"extensions"', $response->body(), 'not the package\'s composer.json');
    }
}
