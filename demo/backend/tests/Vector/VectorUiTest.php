<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Vector\Ui\HostedBundle;
use Kinetis\Vector\Ui\UiPin;
use Kinetis\Vector\Ui\VectorUi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the worker knows about the UI at boot: the version the pin
 * expects and the version the served directory's manifest names, read
 * once and never hashed. A pin or manifest that is missing or malformed
 * is no version, not a boot failure.
 */
final class VectorUiTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = UiFixtures::tempDir('vector-ui');
        mkdir($this->root . '/public/vector/ui', 0755, true);
    }

    protected function tearDown(): void
    {
        UiFixtures::remove($this->root);
    }

    private function pin(string $contents): string
    {
        file_put_contents($this->root . '/ui.json', $contents);

        return $this->root . '/ui.json';
    }

    public function testItHoldsTheExpectedAndTheInstalledUiVersion(): void
    {
        UiFixtures::bundle($this->root . '/public/vector/ui', '0.10.0');

        $ui = VectorUi::fromConfig(new Config([]), $this->root, $this->pin('{"package":"@kinetis/vector-ui","version":"0.11.0"}'));

        self::assertSame('0.11.0', $ui->expectedUiVersion);
        self::assertSame('0.10.0', $ui->installedUiVersion);
        self::assertNotNull($ui->indexHtml);
    }

    public function testTheInstalledVersionIsReadWithoutHashingTheFiles(): void
    {
        UiFixtures::bundle($this->root . '/public/vector/ui', '0.11.0');
        file_put_contents($this->root . '/public/vector/ui/_nuxt/shared.css', 'edited after the install');

        self::assertSame('0.11.0', VectorUi::fromConfig(new Config([]), $this->root, $this->pin('{}'))->installedUiVersion);
    }

    public function testAMalformedPinIsNoExpectationAndBootsAnyway(): void
    {
        $ui = VectorUi::fromConfig(new Config([]), $this->root, $this->pin('{"package":"@kinetis/vector-ui","version":"^0.11"}'));

        self::assertNull($ui->expectedUiVersion);
        self::assertNull($ui->installedUiVersion, 'no UI installed');
    }

    public function testAMissingPinAndAMalformedManifestAreNoVersions(): void
    {
        file_put_contents($this->root . '/public/vector/ui/' . HostedBundle::MANIFEST, 'not json');

        $ui = VectorUi::fromConfig(new Config([]), $this->root, $this->root . '/absent.json');

        self::assertNull($ui->expectedUiVersion);
        self::assertNull($ui->installedUiVersion);
    }

    public function testVectorUiDirNamesTheServedDirectory(): void
    {
        $dir = $this->root . '/elsewhere';
        mkdir($dir);
        UiFixtures::bundle($dir, '0.9.0');

        $ui = VectorUi::fromConfig(new Config(['VECTOR_UI_DIR' => $dir]), $this->root);

        self::assertSame($dir, $ui->publishedDir);
        self::assertSame('0.9.0', $ui->installedUiVersion);
        self::assertSame(UiPin::fromFile(UiPin::path())->version, $ui->expectedUiVersion, 'the package\'s own pin by default');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptySettings(): iterable
    {
        yield 'VECTOR_UI_DIR' => ['VECTOR_UI_DIR'];
        yield 'VECTOR_LOGIN_URL' => ['VECTOR_LOGIN_URL'];
        yield 'VECTOR_LOGOUT_URL' => ['VECTOR_LOGOUT_URL'];
    }

    /**
     * `KEY=` is unset, as a Compose passthrough such as
     * `VECTOR_LOGIN_URL: ${VECTOR_LOGIN_URL:-}` writes it: the setting
     * falls back to its default rather than to an empty URL or directory.
     */
    #[DataProvider('emptySettings')]
    public function testAnEmptySettingFallsBackToItsDefault(string $key): void
    {
        UiFixtures::bundle($this->root . '/public/vector/ui', '0.10.0');
        $config = new Config([$key => '']);

        $ui = VectorUi::fromConfig($config, $this->root, $this->pin('{}'));

        self::assertSame('/login', $ui->loginUrl);
        self::assertSame('/logout', $ui->logoutUrl);
        self::assertSame($this->root . '/public/vector/ui', $ui->publishedDir);
        self::assertSame($this->root . '/public/vector/ui', VectorUi::directory($config, $this->root));
        self::assertSame('0.10.0', $ui->installedUiVersion, 'read from the default directory');
    }

    public function testTheLoginAndLogoutUrlsAreTheConfiguredOnes(): void
    {
        $ui = VectorUi::fromConfig(new Config(['VECTOR_LOGIN_URL' => '/sso/start', 'VECTOR_LOGOUT_URL' => '/sso/end']), $this->root, $this->pin('{}'));

        self::assertSame('/sso/start', $ui->loginUrl);
        self::assertSame('/sso/end', $ui->logoutUrl);
    }
}
