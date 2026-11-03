<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Vector\PackageBootstrap;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Translation\NullTranslator;
use Kinetis\Vector\Translation\TranslatorInterface;
use PHPUnit\Framework\TestCase;

/**
 * The translator Vector's bootstrap binds: Vector's own translations,
 * with the application's VECTOR_TRANSLATIONS_DIR layered over them key by
 * key, unless the application binds a translator of its own.
 */
final class TranslationLayersTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = UiFixtures::tempDir('translation-layers');
    }

    protected function tearDown(): void
    {
        UiFixtures::remove($this->root);
    }

    public function testTheApplicationOverridesVectorAndVectorsOwnStillApply(): void
    {
        $this->translations($this->root . '/translations', ['de' => ['Branding' => 'App-Branding', 'Only in the app' => 'Nur in der App']]);

        $translator = $this->boot('translations');

        self::assertSame('App-Branding', $translator->translate('Branding', 'de'), 'Vector and the application define it: the application wins');
        self::assertSame('Hell', $translator->translate('Light', 'de'), "Vector's own still apply");
        self::assertSame('Nur in der App', $translator->translate('Only in the app', 'de-AT'), 'a regional tag falls back to its language');
    }

    public function testWithoutAnApplicationDirectoryVectorsOwnApply(): void
    {
        $translator = $this->boot('');

        self::assertSame('Hell', $translator->translate('Light', 'de'));
        self::assertSame('Branding', $translator->translate('Branding', 'de'));
    }

    public function testAMissingApplicationDirectoryFailsTheBoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('/translations" does not exist.');

        $this->boot('translations');
    }

    public function testATranslatorTheApplicationBindsIsKept(): void
    {
        $own = new NullTranslator();

        self::assertSame($own, $this->boot('', $own));
    }

    /**
     * Runs Vector's bootstrap on an application scope of its own, with an
     * empty registry, and returns the translator it binds.
     */
    private function boot(string $translationsDir, ?TranslatorInterface $bound = null): TranslatorInterface
    {
        $app = new AppScope();
        $app->instance(ProjectRoot::class, new ProjectRoot($this->root));
        $app->instance(VectorRegistry::class, VectorRegistry::fromArray(['version' => VectorRegistry::VERSION, 'resources' => [], 'settings' => [], 'dashboards' => []]));

        if ($bound !== null) {
            $app->instance(TranslatorInterface::class, $bound);
        }

        (new PackageBootstrap())->register($app, new Config(['VECTOR_UI_DIR' => $this->root . '/ui', 'VECTOR_TRANSLATIONS_DIR' => $translationsDir]));
        $translator = $app->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator;
    }

    /** @param array<string, array<string, string>> $messages locale => key => message */
    private function translations(string $dir, array $messages): void
    {
        @mkdir($dir, 0755, true);

        foreach ($messages as $locale => $entries) {
            file_put_contents("{$dir}/{$locale}.php", '<?php return ' . var_export($entries, true) . ';');
        }
    }
}
