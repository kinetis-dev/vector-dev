<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Tests\Vector\Fixtures\UiExtensionPackage\FixtureBootstrap;
use App\Tests\Vector\Fixtures\UiExtensionPackage\FixtureBooted;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Cache\PluginDiscovery;
use Kinetis\Cache\RoutesFile;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Vector\PackageBootstrap;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Ui\ExtensionModules;
use Kinetis\Vector\Ui\PackageExtensions;
use PHPUnit\Framework\TestCase;

/**
 * A Composer package registers its own UI extensions by declaring them
 * in `extra.kinetis.vector.extensions`. The declarations are read from
 * Composer's installed.json, never from a bootstrap, so a package
 * Composer records — and the framework boots — before kinetis/vector is
 * registered all the same. Only files inside the package are taken.
 */
final class PackageExtensionsTest extends TestCase
{
    private const string FIXTURE = 'acme/vector-queues-fixture';

    private string $root;

    private string $log;

    private string $previousLog;

    protected function setUp(): void
    {
        $this->root = UiFixtures::tempDir('package-extensions');
        $this->log = $this->root . '/error.log';
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLog);
        UiFixtures::remove($this->root);
    }

    public function testOnlyDeclaredFilesInsideThePackageAreRegistered(): void
    {
        $this->install([self::FIXTURE => self::fixtureDir()]);

        self::assertSame([
            ['package' => self::FIXTURE, 'root' => 'vendor/composer/../' . self::FIXTURE, 'entry' => 'resources/ui/queues.js'],
            ['package' => self::FIXTURE, 'root' => 'vendor/composer/../' . self::FIXTURE, 'entry' => 'resources/ui/more/panel.mjs'],
        ], PackageExtensions::discover($this->root));

        $log = (string) file_get_contents($this->log);

        foreach (['"../escape.js"', '"/etc/passwd.js"', '"resources/./ui/queues.js"', '"resources/.hidden.js"', '"README.md"', '42'] as $refused) {
            self::assertStringContainsString("the entry {$refused} is not a relative path to a .js or .mjs file inside the package", $log);
        }

        self::assertStringContainsString('the entry "resources/ui/missing.js" is not a file inside the package', $log);
        self::assertStringContainsString('the entry "resources/other/queues.js" has the same file name as "resources/ui/queues.js"', $log);
    }

    public function testASymlinkOutOfThePackageIsNotRegistered(): void
    {
        $package = $this->root . '/linking';
        mkdir($package);
        file_put_contents($this->root . '/outside.js', 'export default () => {}');
        symlink($this->root . '/outside.js', $package . '/inside.js');
        $this->install(['acme/linking' => $package], ['acme/linking' => ['inside.js']]);

        self::assertSame([], PackageExtensions::discover($this->root));
        self::assertStringContainsString('the entry "inside.js" is not a file inside the package', (string) file_get_contents($this->log));
    }

    public function testTheDeclarationsDoNotDependOnTheOrderComposerRecords(): void
    {
        $this->install(['zeta/ui' => $this->package('zeta', ['z.js']), 'alpha/ui' => $this->package('alpha', ['a.js'])]);
        $reversed = PackageExtensions::discover($this->root);

        $this->install(['alpha/ui' => $this->root . '/alpha', 'zeta/ui' => $this->root . '/zeta']);

        self::assertSame($reversed, PackageExtensions::discover($this->root));
        self::assertSame(['alpha/ui', 'zeta/ui'], array_column($reversed, 'package'));
    }

    /**
     * The fixture package is recorded, and so booted, before
     * kinetis/vector — the order installed.json gives a vendor named
     * before `kinetis`. Its bootstrap runs while Vector's modules are not
     * yet bound, and registers nothing; Vector's, run after it, serves
     * its modules anyway.
     */
    public function testAPackageThatBootsBeforeVectorIsRegistered(): void
    {
        $this->install([self::FIXTURE => self::fixtureDir(), 'kinetis/vector' => dirname(__DIR__, 4) . '/packages/vector']);
        $context = new DiscoveryContext($this->root);
        $bootstraps = $context->packageBootstraps();
        self::assertSame([FixtureBootstrap::class, PackageBootstrap::class], $bootstraps, 'the fixture boots first');

        $app = new AppScope();
        $config = new Config(['VECTOR_UI_DIR' => $this->root . '/ui']);
        $app->instance(ProjectRoot::class, new ProjectRoot($this->root));
        $app->instance(Config::class, $config);
        // what the framework binds before the bootstrap chain: Vector's section, with what its compile() reads
        PluginDiscovery::bindInstances($app, [VectorRegistry::class => VectorRegistry::fromArray([
            'version' => VectorRegistry::VERSION,
            'resources' => [],
            'settings' => [],
            'dashboards' => [],
            'uiExtensions' => PackageExtensions::discover($this->root),
        ])]);

        RoutesFile::loadBootstrap($this->root, $bootstraps)($app, $config);

        $booted = $app->get(FixtureBooted::class);
        self::assertInstanceOf(FixtureBooted::class, $booted);
        self::assertFalse($booted->sawVectorModules, "the fixture ran before Vector's bootstrap");

        $modules = $app->get(ExtensionModules::class);
        self::assertInstanceOf(ExtensionModules::class, $modules);
        self::assertSame([
            ExtensionModules::PATH . '/' . self::FIXTURE . '/' . self::hashOf('resources/ui/queues.js') . '/queues.js',
            ExtensionModules::PATH . '/' . self::FIXTURE . '/' . self::hashOf('resources/ui/more/panel.mjs') . '/panel.mjs',
        ], $modules->urls());
    }

    public function testAModuleRemovedAfterTheBuildIsLeftOutAtBoot(): void
    {
        $package = $this->package('gone', ['a.js', 'b.js']);
        $this->install(['acme/gone' => $package]);
        $declared = PackageExtensions::discover($this->root);
        unlink($package . '/a.js');

        $modules = ExtensionModules::load($declared, $this->root);

        self::assertSame([ExtensionModules::PATH . '/acme/gone/' . ExtensionModules::hash('export default () => {}') . '/b.js'], $modules->urls());
        self::assertStringContainsString('the UI extension "a.js" of package "acme/gone" is not readable inside the package', (string) file_get_contents($this->log));
    }

    public function testTheRealProjectCompilesItsDeclarationsIntoTheRegistry(): void
    {
        $registry = VectorRegistry::fromArray(VectorRegistry::compile(new DiscoveryContext(dirname(__DIR__, 2))));

        self::assertSame([], $registry->uiExtensions(), 'no package the demo installs declares one');
        self::assertArrayHasKey('uiExtensions', $registry->toArray());
    }

    public static function fixtureDir(): string
    {
        return __DIR__ . '/Fixtures/UiExtensionPackage';
    }

    public static function hashOf(string $entry): string
    {
        return ExtensionModules::hash((string) file_get_contents(self::fixtureDir() . '/' . $entry));
    }

    /**
     * Writes vendor/composer/installed.json listing $packages in the order
     * given, each linked at vendor/{name} as a path repository is. A
     * package's extra comes from its own composer.json when it has one.
     *
     * @param array<string, string> $packages name => directory
     * @param array<string, list<string>> $extensions name => declared entries, for a package without composer.json
     */
    private function install(array $packages, array $extensions = []): void
    {
        $records = [];

        foreach ($packages as $name => $dir) {
            $link = $this->root . '/vendor/' . $name;

            if (!is_link($link)) {
                @mkdir(dirname($link), 0755, true);
                symlink($dir, $link);
            }

            $manifest = is_file($dir . '/composer.json') ? json_decode((string) file_get_contents($dir . '/composer.json'), true) : [];
            self::assertIsArray($manifest);
            $record = ['name' => $name, 'install-path' => '../' . $name, 'extra' => $manifest['extra'] ?? ['kinetis' => ['vector' => ['extensions' => $extensions[$name] ?? []]]]];

            if (isset($manifest['autoload'])) {
                $record['autoload'] = $manifest['autoload'];
            }

            $records[] = $record;
        }

        @mkdir($this->root . '/vendor/composer', 0755, true);
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => $records], JSON_THROW_ON_ERROR));
    }

    /**
     * A package directory holding $files, each declared as an extension.
     *
     * @param list<string> $files
     */
    private function package(string $dir, array $files): string
    {
        $path = $this->root . '/' . $dir;
        mkdir($path);

        foreach ($files as $file) {
            file_put_contents($path . '/' . $file, 'export default () => {}');
        }

        file_put_contents($path . '/composer.json', json_encode(['extra' => ['kinetis' => ['vector' => ['extensions' => $files]]]], JSON_THROW_ON_ERROR));

        return $path;
    }
}
