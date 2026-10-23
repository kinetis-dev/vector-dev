<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
use Kinetis\Vector\Console\InstallUiCommand;
use Kinetis\Vector\Ui\HostedBundle;
use Kinetis\Vector\Ui\UiInstaller;
use PHPUnit\Framework\TestCase;

/**
 * UiInstaller swapping a staged bundle into a served directory: verified
 * whole before the target is touched, `index.html` then
 * `vector-bundle.json` landing last, and only files two generations old —
 * recorded by a manifest, listed by neither the outgoing nor the incoming
 * one — pruned. Through the command: VECTOR_UI_DIR names the target, and
 * an installed, verifying pin is not fetched again.
 */
final class UiInstallerTest extends TestCase
{
    private string $work;

    private string $target;

    protected function setUp(): void
    {
        $this->work = UiFixtures::tempDir('installer');
        $this->target = $this->work . '/public/vector/ui';
    }

    protected function tearDown(): void
    {
        UiFixtures::remove($this->work);
    }

    /**
     * A stage holding a build of $version, as an extracted tarball leaves it.
     *
     * @param array<string, string> $files
     * @param array<string, mixed> $overrides
     */
    private function stage(string $version, array $files = [], array $overrides = []): string
    {
        $stage = UiFixtures::tempDir('stage');
        UiFixtures::bundle($stage, $version, $files, $overrides);

        return $stage;
    }

    /**
     * Every file under $dir with its contents, for a before/after comparison.
     *
     * @return array<string, string>
     */
    private static function snapshot(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $item) {
            $files[substr((string) $item->getPathname(), strlen($dir) + 1)] = (string) file_get_contents((string) $item->getPathname());
        }

        ksort($files);

        return $files;
    }

    private function install(string $version, ?\Closure $placed = null): HostedBundle
    {
        $stage = $this->stage($version);

        try {
            return new UiInstaller($placed)->install($stage, $this->target, $version);
        } finally {
            UiFixtures::remove($stage);
        }
    }

    public function testNothingIsWrittenWhenTheStageIsRefused(): void
    {
        $this->install('0.10.0');
        $before = self::snapshot($this->target);

        foreach ([
            'another version' => [$this->stage('0.9.0'), '0.11.0'],
            'a changed file' => [(function (): string {
                $stage = $this->stage('0.11.0');
                file_put_contents($stage . '/_nuxt/shared.css', 'tampered');

                return $stage;
            })(), '0.11.0'],
            'a file no manifest lists' => [(function (): string {
                $stage = $this->stage('0.11.0');
                file_put_contents($stage . '/extra.js', 'smuggled');

                return $stage;
            })(), '0.11.0'],
        ] as $case => [$stage, $expected]) {
            try {
                new UiInstaller(static fn (string $path) => self::fail("{$case}: {$path} was written"))->install($stage, $this->target, $expected);
                self::fail("{$case}: the stage was accepted");
            } catch (\UnexpectedValueException) {
                self::assertSame($before, self::snapshot($this->target), $case);
            } finally {
                UiFixtures::remove($stage);
            }
        }
    }

    public function testNoTargetIsCreatedWhenTheFirstStageIsRefused(): void
    {
        $stage = $this->stage('0.9.0');

        try {
            new UiInstaller()->install($stage, $this->target, '0.11.0');
            self::fail('The stage was accepted.');
        } catch (\UnexpectedValueException) {
            self::assertDirectoryDoesNotExist($this->target);
        } finally {
            UiFixtures::remove($stage);
        }
    }

    public function testTheShellAndThenTheManifestAreWrittenLast(): void
    {
        $this->install('0.10.0');
        $order = [];

        $this->install('0.11.0', static function (string $path) use (&$order): void {
            $order[] = $path;
        });

        self::assertSame(['index.html', HostedBundle::MANIFEST], array_slice($order, -2));
        self::assertSame(HostedBundle::PREVIOUS_MANIFEST, $order[count($order) - 3], 'the outgoing manifest is kept before the shell moves');
        self::assertEqualsCanonicalizing(['_nuxt/entry.' . substr(hash('sha256', '0.11.0'), 0, 8) . '.js', '_nuxt/shared.css'], array_slice($order, 0, -3));
        self::assertSame('0.11.0', HostedBundle::verify($this->target, '0.11.0')->version);
        self::assertSame(0644, fileperms($this->target . '/index.html') & 0777);
    }

    public function testOnlyFilesTwoGenerationsOldArePrunedAndUntrackedFilesSurvive(): void
    {
        $chunk = static fn (string $version): string => '_nuxt/entry.' . substr(hash('sha256', $version), 0, 8) . '.js';

        $this->install('0.10.0');
        file_put_contents($this->target . '/robots.txt', 'the deployment\'s own');
        file_put_contents($this->target . '/_nuxt/custom.js', 'not in any manifest');

        $this->install('0.11.0');
        // a worker that booted on 0.10.0 still serves its shell: its chunk stays for one generation
        self::assertFileExists($this->target . '/' . $chunk('0.10.0'));
        self::assertFileExists($this->target . '/' . $chunk('0.11.0'));

        $this->install('0.12.0');
        self::assertFileDoesNotExist($this->target . '/' . $chunk('0.10.0'), 'two generations old: pruned');
        self::assertFileExists($this->target . '/' . $chunk('0.11.0'), 'the outgoing generation keeps its grace');
        self::assertFileExists($this->target . '/' . $chunk('0.12.0'));
        self::assertFileExists($this->target . '/_nuxt/shared.css', 'listed by the new manifest too');
        self::assertSame('the deployment\'s own', file_get_contents($this->target . '/robots.txt'));
        self::assertSame('not in any manifest', file_get_contents($this->target . '/_nuxt/custom.js'));
        self::assertSame('0.12.0', HostedBundle::verify($this->target, '0.12.0')->version);
    }

    public function testReinstallingTheSameBuildKeepsTheGenerationBeforeIt(): void
    {
        $this->install('0.10.0');
        $this->install('0.11.0');
        $this->install('0.11.0');

        self::assertSame('0.10.0', HostedBundle::read($this->target . '/' . HostedBundle::PREVIOUS_MANIFEST)?->version);
    }

    public function testVectorUiDirIsTheTarget(): void
    {
        $elsewhere = $this->work . '/elsewhere/ui';
        $from = $this->stage('0.11.0');
        $output = self::memory();
        $errors = self::memory();

        try {
            $status = new InstallUiCommand($output, $errors)->run(
                CommandArguments::parse(["--from={$from}"]),
                new Config(['VECTOR_UI_DIR' => $elsewhere]),
                $this->work,
                $this->pin('0.11.0'),
                false,
            );
        } finally {
            UiFixtures::remove($from);
        }

        rewind($errors);
        self::assertSame(0, $status, (string) stream_get_contents($errors));
        self::assertSame('0.11.0', HostedBundle::verify($elsewhere, '0.11.0')->version);
        self::assertDirectoryDoesNotExist($this->target);
    }

    public function testAnInstalledVerifyingPinIsNotFetchedAgainUnlessForced(): void
    {
        $this->install('0.11.0');
        $source = new FakeTarballSource();
        $output = self::memory();
        $command = new InstallUiCommand($output, self::memory(), $source);

        self::assertSame(0, $command->run(CommandArguments::parse([]), new Config([]), $this->work, $this->pin('0.11.0'), false));
        self::assertSame([], $source->calls, 'no network access');
        rewind($output);
        self::assertStringContainsString('0.11.0 is already installed', (string) stream_get_contents($output));

        // --force goes to the registry, which here has nothing to give
        self::assertSame(1, $command->run(CommandArguments::parse(['--force']), new Config([]), $this->work, $this->pin('0.11.0'), false));
        self::assertSame(['resolve @kinetis/vector-ui@0.11.0'], $source->calls);
    }

    public function testAnInstalledBuildThatNoLongerVerifiesIsFetchedAgain(): void
    {
        $this->install('0.11.0');
        file_put_contents($this->target . '/_nuxt/shared.css', 'tampered');
        $source = new FakeTarballSource();

        new InstallUiCommand(self::memory(), self::memory(), $source)
            ->run(CommandArguments::parse([]), new Config([]), $this->work, $this->pin('0.11.0'), false);

        self::assertSame(['resolve @kinetis/vector-ui@0.11.0'], $source->calls);
    }

    /** @return resource */
    private static function memory()
    {
        return fopen('php://memory', 'w+') ?: throw new \RuntimeException('No memory stream.');
    }

    private function pin(string $version): string
    {
        file_put_contents($this->work . '/ui.json', json_encode(['package' => '@kinetis/vector-ui', 'version' => $version], JSON_THROW_ON_ERROR));

        return $this->work . '/ui.json';
    }
}
