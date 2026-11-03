<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Cache\DiscoveryContext;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Vector\PackageBootstrap;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Translation\NullTranslator;
use Kinetis\Vector\Translation\PackageTranslations;
use Kinetis\Vector\Translation\TranslatorInterface;
use PHPUnit\Framework\TestCase;

/**
 * A Composer package ships the translations of the labels it emits on
 * the server by declaring one directory in
 * `extra.kinetis.vector.translations`. The declaration is read from
 * Composer's installed.json, compiled into Vector's registry, and layered
 * at boot between Vector's own translations and VECTOR_TRANSLATIONS_DIR:
 * a package overrides Vector, the application overrides both. A bad
 * declaration is the package author's mistake: reported and skipped,
 * never fatal for the application.
 */
final class PackageTranslationsTest extends TestCase
{
    private string $root;

    private string $log;

    private string $previousLog;

    protected function setUp(): void
    {
        $this->root = UiFixtures::tempDir('package-translations');
        $this->log = $this->root . '/error.log';
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLog);
        UiFixtures::remove($this->root);
    }

    public function testAValidDeclarationIsFound(): void
    {
        $this->package('acme/plugin', ['de' => ['Plugin' => 'Erweiterung']]);
        $this->install(['acme/plugin' => 'resources/translations']);

        self::assertSame(
            [['package' => 'acme/plugin', 'root' => 'vendor/composer/../acme/plugin', 'directory' => 'resources/translations']],
            PackageTranslations::discover($this->root),
        );
        self::assertFileDoesNotExist($this->log, 'nothing to report');
    }

    public function testTheDeclarationsAreSortedByPackageName(): void
    {
        foreach (['zeta/plugin', 'alpha/plugin', 'mid/plugin'] as $name) {
            $this->package($name, []);
        }

        $this->install(['zeta/plugin' => 'resources/translations', 'alpha/plugin' => 'resources/translations', 'mid/plugin' => 'resources/translations']);
        $declared = PackageTranslations::discover($this->root);

        self::assertSame(['alpha/plugin', 'mid/plugin', 'zeta/plugin'], array_column($declared, 'package'));

        $this->install(['mid/plugin' => 'resources/translations', 'alpha/plugin' => 'resources/translations', 'zeta/plugin' => 'resources/translations']);
        self::assertSame($declared, PackageTranslations::discover($this->root), 'whatever order Composer records');
    }

    public function testInvalidDeclarationsAreReportedAndSkippedNeverThrown(): void
    {
        $outside = $this->root . '/outside';
        mkdir($outside);
        $invalid = [
            'acme/absolute' => $outside,
            'acme/parent' => '../outside',
            'acme/dot-dot' => 'resources/../resources/translations',
            'acme/dot' => './resources/translations',
            'acme/hidden' => 'resources/.translations',
            'acme/trailing' => 'resources/translations/',
            'acme/empty' => '',
            'acme/file' => 'resources/translations/de.php',
            'acme/missing' => 'resources/nowhere',
            'acme/link' => 'resources/linked',
            'acme/number' => 42,
            'acme/list' => ['resources/translations'],
        ];

        foreach (array_keys($invalid) as $name) {
            $this->package($name, ['de' => ['Plugin' => 'Erweiterung']]);
        }

        mkdir($this->root . '/vendor/acme/hidden/resources/.translations');
        symlink($outside, $this->root . '/vendor/acme/link/resources/linked');
        $this->install($invalid);

        self::assertSame([], PackageTranslations::discover($this->root));

        $log = (string) file_get_contents($this->log);
        $shown = [
            'acme/absolute' => json_encode($outside, JSON_UNESCAPED_SLASHES),
            'acme/parent' => '"../outside"',
            'acme/dot-dot' => '"resources/../resources/translations"',
            'acme/dot' => '"./resources/translations"',
            'acme/hidden' => '"resources/.translations"',
            'acme/trailing' => '"resources/translations/"',
            'acme/empty' => '""',
            'acme/number' => '42',
            'acme/list' => '["resources/translations"]',
        ];

        foreach ($shown as $name => $value) {
            self::assertStringContainsString("package \"{$name}\" declares translations Vector cannot read: extra.kinetis.vector.translations {$value} is not a relative path to a directory inside the package — skipped.", $log);
        }

        foreach (['acme/file' => 'resources/translations/de.php', 'acme/missing' => 'resources/nowhere', 'acme/link' => 'resources/linked'] as $name => $value) {
            self::assertStringContainsString("package \"{$name}\" declares translations Vector cannot read: \"{$value}\" is not a directory inside the package — skipped.", $log);
        }
    }

    public function testAPackageWithoutTheKeyIsIgnored(): void
    {
        $this->package('acme/plain', ['de' => ['Plugin' => 'Erweiterung']]);
        $this->package('acme/ui-only', []);
        $this->install(['acme/plain' => null, 'acme/ui-only' => null], ['acme/ui-only' => ['kinetis' => ['vector' => ['extensions' => []]]]]);

        self::assertSame([], PackageTranslations::discover($this->root));
        self::assertFileDoesNotExist($this->log, 'a package that declares nothing is not reported');
    }

    public function testTheCompiledRegistryCarriesTheDeclarations(): void
    {
        $declared = [['package' => 'acme/plugin', 'root' => 'vendor/composer/../acme/plugin', 'directory' => 'resources/translations']];
        $registry = VectorRegistry::fromArray([...self::emptyRegistry(), 'packageTranslations' => $declared]);

        self::assertSame($declared, $registry->packageTranslations());
        self::assertSame($declared, VectorRegistry::fromArray($registry->toArray())->packageTranslations(), 'round-trips through the cache');
    }

    public function testACacheCompiledWithoutTheKeyLoadsAsNoTranslations(): void
    {
        $registry = VectorRegistry::fromArray([...self::emptyRegistry(), 'uiExtensions' => []]);

        self::assertSame([], $registry->packageTranslations());
    }

    public function testAMalformedCacheEntryIsRefused(): void
    {
        $this->expectException(InvalidCacheArtifactException::class);

        VectorRegistry::fromArray([...self::emptyRegistry(), 'packageTranslations' => [['package' => 'acme/plugin', 'root' => 'vendor/acme/plugin']]]);
    }

    public function testTheRealProjectCompilesItsDeclarationsIntoTheRegistry(): void
    {
        $data = VectorRegistry::compile(new DiscoveryContext(dirname(__DIR__, 2)));

        // the queue dashboard's labels are Vector's own translations now: nothing the demo installs declares any
        self::assertSame([], $data['packageTranslations'] ?? null, 'no package the demo installs declares translations');
        self::assertSame($data['packageTranslations'], VectorRegistry::fromArray($data)->packageTranslations());
    }

    public function testTheApplicationOverridesAPackageWhichOverridesVector(): void
    {
        $this->package('acme/alpha', ['de' => [
            'Branding' => 'Alpha-Branding',
            'Languages' => 'Alpha-Sprachen',
            'Only in a package' => 'Nur in einem Paket',
            'In both packages' => 'Alpha',
        ]]);
        $this->package('acme/zeta', ['de' => ['In both packages' => 'Zeta']]);
        // recorded zeta first: package-name order decides, not Composer's
        $this->install(['acme/zeta' => 'resources/translations', 'acme/alpha' => 'resources/translations']);
        $this->translations($this->root . '/translations', ['de' => ['Branding' => 'App-Branding']]);

        $translator = $this->boot(PackageTranslations::discover($this->root), 'translations');

        self::assertSame('App-Branding', $translator->translate('Branding', 'de'), 'Vector, a package and the application define it: the application wins');
        self::assertSame('Alpha-Sprachen', $translator->translate('Languages', 'de'), 'Vector and a package define it: the package wins');
        self::assertSame('Nur in einem Paket', $translator->translate('Only in a package', 'de'));
        self::assertSame('Zeta', $translator->translate('In both packages', 'de'), 'the later package by name wins');
        self::assertSame('Hell', $translator->translate('Light', 'de'), "Vector's own still apply");
        self::assertSame('Nur in einem Paket', $translator->translate('Only in a package', 'de-AT'), 'a regional tag falls back to its language');
    }

    public function testAPackageDirectoryGoneSinceTheBuildIsSkippedAtBoot(): void
    {
        $this->package('acme/gone', ['de' => ['Gone' => 'Weg']]);
        $this->package('acme/kept', ['de' => ['Kept' => 'Behalten']]);
        $this->install(['acme/gone' => 'resources/translations', 'acme/kept' => 'resources/translations']);
        $declared = PackageTranslations::discover($this->root);
        self::assertCount(2, $declared);
        UiFixtures::remove($this->root . '/vendor/acme/gone/resources/translations');

        $translator = $this->boot($declared, '');

        self::assertNull($translator->translate('Gone', 'de'));
        self::assertSame('Behalten', $translator->translate('Kept', 'de'));
        self::assertStringContainsString('package "acme/gone" declares translations Vector cannot read: "resources/translations" is no longer a directory inside the package — skipped.', (string) file_get_contents($this->log));
    }

    public function testAMissingApplicationDirectoryStillFailsTheBoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('/translations" does not exist.');

        $this->boot([], 'translations');
    }

    public function testATranslatorTheApplicationBindsIsKept(): void
    {
        $this->package('acme/plugin', ['de' => ['Plugin' => 'Erweiterung']]);
        $this->install(['acme/plugin' => 'resources/translations']);
        $own = new NullTranslator();

        self::assertSame($own, $this->boot(PackageTranslations::discover($this->root), '', $own));
    }

    /**
     * Runs Vector's bootstrap on an application scope of its own, with a
     * registry carrying $declared, and returns the translator it binds.
     *
     * @param list<array{package: string, root: string, directory: string}> $declared
     */
    private function boot(array $declared, string $translationsDir, ?TranslatorInterface $bound = null): TranslatorInterface
    {
        $app = new AppScope();
        $app->instance(ProjectRoot::class, new ProjectRoot($this->root));
        $app->instance(VectorRegistry::class, VectorRegistry::fromArray([...self::emptyRegistry(), 'packageTranslations' => $declared]));

        if ($bound !== null) {
            $app->instance(TranslatorInterface::class, $bound);
        }

        (new PackageBootstrap())->register($app, new Config(['VECTOR_UI_DIR' => $this->root . '/ui', 'VECTOR_TRANSLATIONS_DIR' => $translationsDir]));
        $translator = $app->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator;
    }

    /** @return array{version: int, resources: array<string, array<string, mixed>>, settings: array<string, array<string, mixed>>, dashboards: array<string, array<string, mixed>>} */
    private static function emptyRegistry(): array
    {
        return ['version' => VectorRegistry::VERSION, 'resources' => [], 'settings' => [], 'dashboards' => []];
    }

    /**
     * A package at vendor/{name} with resources/translations/{locale}.php.
     *
     * @param array<string, array<string, string>> $messages locale => key => message
     */
    private function package(string $name, array $messages): void
    {
        $this->translations($this->root . '/vendor/' . $name . '/resources/translations', $messages);
    }

    /** @param array<string, array<string, string>> $messages locale => key => message */
    private function translations(string $dir, array $messages): void
    {
        @mkdir($dir, 0755, true);

        foreach ($messages as $locale => $entries) {
            file_put_contents("{$dir}/{$locale}.php", '<?php return ' . var_export($entries, true) . ';');
        }
    }

    /**
     * Writes vendor/composer/installed.json listing $packages in the order
     * given, as Composer records a package installed at vendor/{name}.
     *
     * @param array<string, mixed> $packages name => the declared translations value, null for no key at all
     * @param array<string, array<string, mixed>> $extra name => the whole extra, for a package declaring no translations
     */
    private function install(array $packages, array $extra = []): void
    {
        $records = [];

        foreach ($packages as $name => $translations) {
            $record = ['name' => $name, 'install-path' => '../' . $name];

            if ($translations !== null) {
                $record['extra'] = ['kinetis' => ['vector' => ['translations' => $translations]]];
            } elseif (isset($extra[$name])) {
                $record['extra'] = $extra[$name];
            }

            $records[] = $record;
        }

        @mkdir($this->root . '/vendor/composer', 0755, true);
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => $records], JSON_THROW_ON_ERROR));
    }
}
