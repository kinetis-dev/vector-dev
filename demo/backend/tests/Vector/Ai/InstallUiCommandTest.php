<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Tests\Fixtures\CommandOutput;
use App\Tests\Vector\FakeTarballSource;
use App\Tests\Vector\UiFixtures;
use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
use Kinetis\Vector\Console\InstallUiCommand as VectorInstallUiCommand;
use Kinetis\Vector\Ui\HostedBundle;
use Kinetis\Vector\Ui\UiPin;
use Kinetis\VectorAi\Console\InstallUiCommand;
use PHPUnit\Framework\TestCase;

/**
 * vector-ai:install-ui installs the @kinetis/vector-ai-ui bundle its pin
 * names, built with the @kinetis/vector-ui release kinetis/vector pins,
 * into the served directory in place of the plain one; `--check` holds
 * the served bundle to both pins, and `vector:install-ui --check` accepts
 * it too. Fixture pins in a temporary project root, a fake registry.
 */
final class InstallUiCommandTest extends TestCase
{
    private string $work;

    private string $target;

    private CommandOutput $capture;

    #[\Override]
    protected function setUp(): void
    {
        $this->work = UiFixtures::tempDir('vector-ai-install-ui');
        $this->target = $this->work . '/public/vector/ui';
        $this->capture = new CommandOutput();
        file_put_contents($this->work . '/ai-ui.json', '{"package":"@kinetis/vector-ai-ui","version":"0.1.0"}');
        file_put_contents($this->work . '/ui.json', '{"package":"@kinetis/vector-ui","version":"0.13.0"}');
    }

    #[\Override]
    protected function tearDown(): void
    {
        UiFixtures::remove($this->work);
    }

    /**
     * @param list<string> $argv
     */
    private function command(array $argv, ?FakeTarballSource $source = null, bool $devInstall = false): int
    {
        return new InstallUiCommand($this->capture->output, $source)
            ->run(CommandArguments::parse($argv), new Config([]), $this->work, $this->work . '/ai-ui.json', $this->work . '/ui.json', $devInstall);
    }

    /**
     * A built @kinetis/vector-ai-ui bundle of $version, including
     * @kinetis/vector-ui at $included (none when null).
     *
     * @param array<string, mixed> $overrides
     */
    private function built(string $version = '0.1.0', ?string $included = '0.13.0', array $overrides = []): string
    {
        $dir = $this->work . '/built-' . bin2hex(random_bytes(4));
        mkdir($dir);
        UiFixtures::bundle($dir, $version, [], [
            'package' => '@kinetis/vector-ai-ui',
            ...($included === null ? [] : ['includes' => ['@kinetis/vector-ui' => $included]]),
            ...$overrides,
        ]);

        return $dir;
    }

    public function testItInstallsTheAiBundleFromADirectory(): void
    {
        self::assertSame(0, $this->command(['--from=' . $this->built()]), $this->capture->stderr());

        self::assertStringContainsString("Installed @kinetis/vector-ai-ui 0.1.0 (3 files) into {$this->target}.", $this->capture->stdout());
        $bundle = HostedBundle::verify($this->target, '0.1.0', '@kinetis/vector-ai-ui', ['@kinetis/vector-ui' => '0.13.0']);
        self::assertSame('0.13.0', $bundle->carries(UiPin::PACKAGE));
    }

    public function testItReplacesThePlainBundle(): void
    {
        mkdir($this->target, 0755, true);
        UiFixtures::bundle($this->target, '0.13.0');

        self::assertSame(0, $this->command(['--from=' . $this->built()]), $this->capture->stderr());

        self::assertSame('@kinetis/vector-ai-ui', HostedBundle::read($this->target . '/' . HostedBundle::MANIFEST)?->package);
    }

    public function testItInstallsThePinnedVersionFromTheRegistry(): void
    {
        $archive = UiFixtures::npmTarball($this->built(), $this->work . '/vector-ai-ui.tgz');
        $source = new FakeTarballSource('https://registry.example/@kinetis/vector-ai-ui/-/vector-ai-ui-0.1.0.tgz', UiFixtures::integrity($archive), $archive);

        self::assertSame(0, $this->command([], $source), $this->capture->stderr());

        self::assertSame('resolve @kinetis/vector-ai-ui@0.1.0', $source->calls[0]);
        self::assertSame('0.1.0', HostedBundle::read($this->target . '/' . HostedBundle::MANIFEST)?->version);
    }

    /**
     * @return iterable<string, array{\Closure(self): string, string}>
     */
    public static function refused(): iterable
    {
        yield 'another release' => [static fn (self $test): string => $test->built('0.2.0'), 'it is version 0.2.0; 0.1.0 is expected'];
        yield 'built with another @kinetis/vector-ui' => [static fn (self $test): string => $test->built('0.1.0', '0.12.0'), 'it was built with @kinetis/vector-ui 0.12.0; 0.13.0 is expected'];
        yield 'naming no @kinetis/vector-ui' => [static fn (self $test): string => $test->built('0.1.0', null), 'it does not say which @kinetis/vector-ui it was built with'];
        yield 'the plain bundle' => [static function (self $test): string {
            $dir = $test->work . '/plain';
            mkdir($dir);
            UiFixtures::bundle($dir, '0.1.0');

            return $dir;
        }, 'it was built for @kinetis/vector-ui, not @kinetis/vector-ai-ui'];
    }

    /**
     * @param \Closure(self): string $from
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testItRefusesABundleThatDoesNotMatchBothPins(\Closure $from, string $cause): void
    {
        self::assertSame(1, $this->command(['--from=' . $from($this)]));

        self::assertStringContainsString($cause, $this->capture->stderr());
        self::assertStringContainsString('Run `vendor/bin/kinetis vector-ai:install-ui`', $this->capture->stderr());
        self::assertDirectoryDoesNotExist($this->target);
    }

    public function testADevBuildIsAcceptedOnADevInstall(): void
    {
        self::assertSame(0, $this->command(['--from=' . $this->built('dev-0123456789ab')], null, true), $this->capture->stderr());

        self::assertSame('dev-0123456789ab', HostedBundle::read($this->target . '/' . HostedBundle::MANIFEST)?->version);
    }

    public function testBothChecksPassOnAnInstalledBundleMatchingBothPins(): void
    {
        $this->command(['--from=' . $this->built()]);

        self::assertSame(0, $this->command(['--check']), $this->capture->stderr());
        self::assertStringContainsString('@kinetis/vector-ai-ui 0.1.0 in ' . $this->target . ' verifies against its vector-bundle.json and is in sync.', $this->capture->stdout());

        $vector = new VectorInstallUiCommand($this->capture->output)
            ->run(CommandArguments::parse(['--check']), new Config([]), $this->work, $this->work . '/ui.json', false);

        self::assertSame(0, $vector, $this->capture->stderr());
        self::assertStringContainsString('includes @kinetis/vector-ui 0.13.0', $this->capture->stdout());
    }

    public function testCheckFailsWhenKinetisVectorPinsAnotherUi(): void
    {
        $this->command(['--from=' . $this->built()]);
        file_put_contents($this->work . '/ui.json', '{"package":"@kinetis/vector-ui","version":"0.14.0"}');

        self::assertSame(1, $this->command(['--check']));
        self::assertStringContainsString('it was built with @kinetis/vector-ui 0.13.0; 0.14.0 is expected', $this->capture->stderr());
    }

    public function testCheckFailsOnThePlainBundle(): void
    {
        mkdir($this->target, 0755, true);
        UiFixtures::bundle($this->target, '0.13.0');

        self::assertSame(1, $this->command(['--check']));
        self::assertStringContainsString('it was built for @kinetis/vector-ui, not @kinetis/vector-ai-ui', $this->capture->stderr());
    }

    public function testCheckFailsWithNothingInstalled(): void
    {
        self::assertSame(1, $this->command(['--check']));
        self::assertStringContainsString('cannot verify: no vector-bundle.json. Run `vendor/bin/kinetis vector-ai:install-ui` to install @kinetis/vector-ai-ui 0.1.0.', $this->capture->stderr());
    }

    public function testItNeedsKinetisVectorsPin(): void
    {
        unlink($this->work . '/ui.json');

        self::assertSame(1, $this->command(['--check']));
        self::assertStringContainsString('kinetis/vector pins no @kinetis/vector-ui release', $this->capture->stderr());
    }

    public function testUsageErrorsNameTheCommand(): void
    {
        self::assertSame(2, $this->command(['--check', '--force']));
        self::assertStringContainsString('Usage: vector-ai:install-ui', $this->capture->stderr());
    }

    public function testThePackagePinsTheAiLayerItShipsWith(): void
    {
        $package = json_decode((string) file_get_contents(dirname(__DIR__, 5) . '/packages/vector-ai-ui/package.json'), true, 8, JSON_THROW_ON_ERROR);
        $pin = UiPin::fromFile(InstallUiCommand::pinPath(), InstallUiCommand::PACKAGE);

        self::assertIsArray($package);
        self::assertSame($package['version'], $pin->version);
    }
}
