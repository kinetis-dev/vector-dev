<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Tests\Vector\Fixtures\UiExtensionPackage\FixtureBootstrap;
use App\Tests\Vector\Fixtures\UiExtensionPackage\FixtureBooted;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Testing\TestApplication;
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
     * The framework's own boot, end to end, for a project where the
     * fixture package is recorded — and so booted — before kinetis/vector,
     * as installed.json orders a vendor named before `kinetis`: discovery
     * compiles Vector's registry section, BootSequence::run() binds it and
     * runs the bootstrap chain, routes are discovered, and the module is
     * fetched through the Kernel.
     *
     * Order independence rests on kinetis/framework v1.24.1
     * src/Cache/BootSequence.php, lines 86-92 of run():
     * `PluginDiscovery::bindInstances($app, $pluginInstances);` comes
     * before `RoutesFile::loadBootstrap($projectRoot, $packageBootstraps)($app, $config);`,
     * so the compiled section, declarations included, is bound before any
     * package bootstrap runs. TestApplication::boot() (src/Testing/TestApplication.php,
     * line 108) and HttpStartup (src/Runtime/HttpStartup.php, line 205) both go
     * through it, which is why this test drives TestApplication.
     */
    public function testTheFrameworksBootServesAPackageThatBootsBeforeVector(): void
    {
        $this->installWithTheDemosPackages();
        $bootstraps = (new DiscoveryContext($this->root))->packageBootstraps();
        self::assertSame(FixtureBootstrap::class, $bootstraps[0], 'the fixture boots first');
        self::assertContains(PackageBootstrap::class, $bootstraps);

        $application = TestApplication::boot($this->root, ['VECTOR_UI_DIR' => $this->root . '/ui', 'VECTOR_UI_EXTENSIONS' => '', 'VECTOR_TRANSLATIONS_DIR' => '']);

        try {
            $booted = $application->get(FixtureBooted::class);
            self::assertInstanceOf(FixtureBooted::class, $booted);
            self::assertFalse($booted->sawVectorModules, "the fixture ran before Vector's bootstrap");

            $registry = $application->get(VectorRegistry::class);
            self::assertInstanceOf(VectorRegistry::class, $registry);
            // the fixture's, then kinetis/vector-queues' own: the demo installs it, and a package's modules come in package-name order
            self::assertSame(
                [[self::FIXTURE, 'resources/ui/queues.js'], [self::FIXTURE, 'resources/ui/more/panel.mjs'], ['kinetis/vector-queues', 'resources/ui/queues.js']],
                array_map(static fn (array $declared): array => [$declared['package'], $declared['entry']], $registry->uiExtensions()),
                'compiled by discovery',
            );

            $modules = $application->get(ExtensionModules::class);
            self::assertInstanceOf(ExtensionModules::class, $modules);
            $url = ExtensionModules::PATH . '/' . self::FIXTURE . '/' . self::hashOf('resources/ui/queues.js') . '/queues.js';
            self::assertSame([$url, ExtensionModules::PATH . '/' . self::FIXTURE . '/' . self::hashOf('resources/ui/more/panel.mjs') . '/panel.mjs'], array_slice($modules->urls(), 0, 2));

            $response = $application->client()->get($url)->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
            self::assertSame((string) file_get_contents(self::fixtureDir() . '/resources/ui/queues.js'), $response->body());
        } finally {
            $application->dispose();
        }
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

        self::assertSame(
            [['package' => 'kinetis/vector-queues', 'root' => 'vendor/composer/../kinetis/vector-queues', 'entry' => 'resources/ui/queues.js']],
            $registry->uiExtensions(),
            'kinetis/vector-queues is the one package the demo installs that declares one',
        );
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
     * A project of its own holding the demo's installed packages, each
     * linked at vendor/{name}, with the fixture package recorded first.
     */
    private function installWithTheDemosPackages(): void
    {
        $vendor = dirname(__DIR__, 2) . '/vendor';
        $installed = json_decode((string) file_get_contents($vendor . '/composer/installed.json'), true);
        self::assertIsArray($installed);
        self::assertIsArray($installed['packages'] ?? null);
        $fixture = json_decode((string) file_get_contents(self::fixtureDir() . '/composer.json'), true);
        self::assertIsArray($fixture);
        $records = [['name' => self::FIXTURE, 'install-path' => '../' . self::FIXTURE, 'extra' => $fixture['extra']]];
        @mkdir($this->root . '/vendor/acme', 0755, true);
        symlink(self::fixtureDir(), $this->root . '/vendor/' . self::FIXTURE);

        foreach ($installed['packages'] as $package) {
            self::assertIsArray($package);
            self::assertIsString($package['name'] ?? null);
            $name = $package['name'];

            // A metapackage (psalm/psalm-plugin-api) has no files: Composer records it with a null install-path.
            if (($package['install-path'] ?? null) === null) {
                $records[] = $package;

                continue;
            }

            @mkdir(dirname($this->root . '/vendor/' . $name), 0755, true);
            symlink((string) realpath($vendor . '/' . $name), $this->root . '/vendor/' . $name);
            $records[] = [...$package, 'install-path' => '../' . $name];
        }

        @mkdir($this->root . '/vendor/composer', 0755, true);
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => $records], JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/composer.json', '{"name": "acme/app"}');
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
