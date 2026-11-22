<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Tests\Fixtures\CommandOutput;
use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
use Kinetis\Vector\Console\InstallUiCommand;
use Kinetis\Vector\Ui\HostedBundle;
use Kinetis\Vector\Ui\VectorUi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A composed bundle (another package's, built from @kinetis/vector-ui
 * plus its own layer) names the @kinetis/vector-ui release it includes
 * in its manifest's `includes`. HostedBundle reads and verifies that
 * member, and kinetis/vector holds what a composed bundle includes, not
 * its own version, to the pin.
 */
final class ComposedBundleTest extends TestCase
{
    private const string COMPOSED = '@acme/admin-plus';

    private string $root;

    private string $dir;

    protected function setUp(): void
    {
        $this->root = UiFixtures::tempDir('composed');
        $this->dir = $this->root . '/public/vector/ui';
        mkdir($this->dir, 0755, true);
        file_put_contents($this->root . '/ui.json', '{"package":"@kinetis/vector-ui","version":"0.11.0"}');
    }

    protected function tearDown(): void
    {
        UiFixtures::remove($this->root);
    }

    /** A composed bundle of version 2.0.0 including @kinetis/vector-ui at $included. */
    private function composed(?string $included): void
    {
        UiFixtures::bundle($this->dir, '2.0.0', [], ['package' => self::COMPOSED] + ($included === null ? [] : ['includes' => ['@kinetis/vector-ui' => $included]]));
    }

    public function testTheManifestCarriesWhatItIncludes(): void
    {
        $this->composed('0.11.0');

        $bundle = HostedBundle::verify($this->dir, '2.0.0', self::COMPOSED, ['@kinetis/vector-ui' => '0.11.0']);

        self::assertSame(['@kinetis/vector-ui' => '0.11.0'], $bundle->includes);
        self::assertSame('0.11.0', $bundle->carries('@kinetis/vector-ui'));
        self::assertSame('2.0.0', $bundle->carries(self::COMPOSED));
        self::assertNull($bundle->carries('@acme/other'));
    }

    public function testAPlainBundleIncludesNothing(): void
    {
        UiFixtures::bundle($this->dir, '0.11.0');

        $bundle = HostedBundle::verify($this->dir, '0.11.0');

        self::assertSame([], $bundle->includes);
        self::assertSame('0.11.0', $bundle->carries('@kinetis/vector-ui'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedIncludes(): iterable
    {
        yield 'not an object' => ['0.11.0'];
        yield 'a list' => [['0.11.0']];
        yield 'a version that is no version' => [['@kinetis/vector-ui' => 'latest']];
        yield 'a version that is no string' => [['@kinetis/vector-ui' => 11]];
        yield 'an empty package name' => [['' => '0.11.0']];
        yield 'the bundle itself' => [[self::COMPOSED => '2.0.0']];
    }

    #[DataProvider('malformedIncludes')]
    public function testMalformedIncludesMakeTheManifestMalformed(mixed $includes): void
    {
        UiFixtures::bundle($this->dir, '2.0.0', [], ['package' => self::COMPOSED, 'includes' => $includes]);

        self::assertNull(HostedBundle::read($this->dir . '/' . HostedBundle::MANIFEST));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function refusedIncludes(): iterable
    {
        yield 'another release' => ['0.10.0', 'it was built with @kinetis/vector-ui 0.10.0; 0.11.0 is expected'];
        yield 'none named' => [null, 'it does not say which @kinetis/vector-ui it was built with; 0.11.0 is expected'];
    }

    #[DataProvider('refusedIncludes')]
    public function testVerifyRefusesABundleWithoutTheExpectedInclude(?string $included, string $cause): void
    {
        $this->composed($included);

        try {
            HostedBundle::verify($this->dir, '2.0.0', self::COMPOSED, ['@kinetis/vector-ui' => '0.11.0'], 'acme:install-ui');
            self::fail('verified');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString($cause, $e->getMessage());
            self::assertStringContainsString('Run `vendor/bin/kinetis acme:install-ui`', $e->getMessage());
        }
    }

    public function testVerifyRefusesAnotherPackage(): void
    {
        $this->composed('0.11.0');

        $this->expectExceptionMessage('it was built for @acme/admin-plus, not @kinetis/vector-ui');

        HostedBundle::verify($this->dir, '0.11.0');
    }

    /**
     * @return array{int, string, string}
     */
    private function check(): array
    {
        $capture = new CommandOutput();
        $status = new InstallUiCommand($capture->output)
            ->run(CommandArguments::parse(['--check']), new Config([]), $this->root, $this->root . '/ui.json', false);

        return [$status, $capture->stdout(), $capture->stderr()];
    }

    public function testCheckPassesOnAComposedBundleIncludingThePinnedRelease(): void
    {
        $this->composed('0.11.0');

        [$status, $stdout, $stderr] = $this->check();

        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString("@acme/admin-plus 2.0.0 in {$this->dir} includes @kinetis/vector-ui 0.11.0, verifies against its vector-bundle.json and is in sync.", $stdout);
    }

    public function testCheckRefusesAComposedBundleIncludingAnotherRelease(): void
    {
        $this->composed('0.10.0');

        [$status, , $stderr] = $this->check();

        self::assertSame(1, $status);
        self::assertStringContainsString('it was built with @kinetis/vector-ui 0.10.0; 0.11.0 is expected', $stderr);
    }

    public function testCheckRefusesAComposedBundleThatChangedAfterItsManifest(): void
    {
        $this->composed('0.11.0');
        file_put_contents($this->dir . '/_nuxt/shared.css', 'edited');

        [$status, , $stderr] = $this->check();

        self::assertSame(1, $status);
        self::assertStringContainsString('_nuxt/shared.css changed after vector-bundle.json was written', $stderr);
    }

    public function testCheckRefusesABundleOfAnotherPackageIncludingNothing(): void
    {
        $this->composed(null);

        [$status, , $stderr] = $this->check();

        self::assertSame(1, $status);
        self::assertStringContainsString('it was built for @acme/admin-plus, not @kinetis/vector-ui', $stderr);
    }

    public function testTheWorkerKnowsTheIncludedUiAsTheInstalledOne(): void
    {
        $this->composed('0.11.0');

        $ui = VectorUi::fromConfig(new Config([]), $this->root, $this->root . '/ui.json');

        self::assertSame('0.11.0', $ui->expectedUiVersion);
        self::assertSame('0.11.0', $ui->installedUiVersion);
    }

    public function testTheWorkerKnowsNoUiVersionInABundleIncludingNone(): void
    {
        $this->composed(null);

        self::assertNull(VectorUi::fromConfig(new Config([]), $this->root, $this->root . '/ui.json')->installedUiVersion);
    }
}
