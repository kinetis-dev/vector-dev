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
 * `vector-bundle.json` landing last, and only what the outgoing manifest
 * listed and the incoming one does not pruned. Through the command: VECTOR_UI_DIR names the target, and
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
            $path = (string) $item->getPathname();
            // a link is recorded as where it points, never followed
            $files[substr($path, strlen($dir) + 1)] = is_link($path) ? 'link to ' . readlink($path) : (string) file_get_contents($path);
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

    public function testATargetDirectoryLinkedOutsideIsRefusedAndNothingIsWritten(): void
    {
        $outside = $this->work . '/outside';
        mkdir($outside);
        file_put_contents($outside . '/keep.js', 'outside');
        mkdir($this->target, 0755, true);
        file_put_contents($this->target . '/robots.txt', 'the deployment\'s own');
        symlink($outside, $this->target . '/_nuxt');
        $before = self::snapshot($this->target);
        $stage = $this->stage('0.11.0');

        try {
            new UiInstaller(static fn (string $path) => self::fail("{$path} was written"))->install($stage, $this->target, '0.11.0');
            self::fail('The install went through a link.');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('is a link or leads outside it', $e->getMessage());
        } finally {
            UiFixtures::remove($stage);
        }

        self::assertSame($before, self::snapshot($this->target));
        self::assertSame(['.', '..', 'keep.js'], scandir($outside));
        self::assertSame('outside', file_get_contents($outside . '/keep.js'));
    }

    public function testAPruneThroughADirectoryLinkedOutsideIsRefused(): void
    {
        $this->install('0.10.0');
        // the previous manifest lists _nuxt/old.js; _nuxt now leads outside
        $manifest = json_decode((string) file_get_contents($this->target . '/' . HostedBundle::MANIFEST), true, 8, JSON_THROW_ON_ERROR);
        file_put_contents($this->target . '/_nuxt/old.js', 'old');
        $manifest['files']['_nuxt/old.js'] = hash('sha256', 'old');
        file_put_contents($this->target . '/' . HostedBundle::MANIFEST, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $outside = $this->work . '/outside';
        mkdir($outside);
        file_put_contents($outside . '/old.js', 'outside');
        UiFixtures::remove($this->target . '/_nuxt');
        symlink($outside, $this->target . '/_nuxt');
        $before = self::snapshot($this->target);

        // the new build writes nothing under _nuxt: only the prune would go through the link
        $stage = $this->stage('0.11.0', ['index.html' => '<!doctype html>', 'assets/app.js' => 'app']);

        try {
            new UiInstaller(static fn (string $path) => self::fail("{$path} was written"))->install($stage, $this->target, '0.11.0');
            self::fail('The prune went through a link.');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('is a link or leads outside it', $e->getMessage());
        } finally {
            UiFixtures::remove($stage);
        }

        self::assertSame($before, self::snapshot($this->target));
        self::assertSame('outside', file_get_contents($outside . '/old.js'));
        self::assertTrue(is_link($this->target . '/_nuxt'));
    }

    public function testTheShellAndThenTheManifestAreWrittenLast(): void
    {
        $this->install('0.10.0');
        $order = [];

        $this->install('0.11.0', static function (string $path) use (&$order): void {
            $order[] = $path;
        });

        self::assertSame(['index.html', HostedBundle::MANIFEST], array_slice($order, -2));
        self::assertEqualsCanonicalizing(['_nuxt/entry.' . substr(hash('sha256', '0.11.0'), 0, 8) . '.js', '_nuxt/shared.css'], array_slice($order, 0, -2));
        self::assertSame('0.11.0', HostedBundle::verify($this->target, '0.11.0')->version);
        self::assertSame(0644, fileperms($this->target . '/index.html') & 0777);
    }

    public function testOnlyWhatTheOutgoingManifestListedIsPrunedAndUntrackedFilesSurvive(): void
    {
        $chunk = static fn (string $version): string => '_nuxt/entry.' . substr(hash('sha256', $version), 0, 8) . '.js';

        $this->install('0.10.0');
        file_put_contents($this->target . '/robots.txt', 'the deployment\'s own');
        file_put_contents($this->target . '/_nuxt/custom.js', 'not in any manifest');

        $this->install('0.11.0');
        self::assertFileDoesNotExist($this->target . '/' . $chunk('0.10.0'), 'listed by the outgoing manifest only: pruned');
        self::assertFileExists($this->target . '/' . $chunk('0.11.0'));
        self::assertFileExists($this->target . '/_nuxt/shared.css', 'listed by the new manifest too');
        self::assertSame('the deployment\'s own', file_get_contents($this->target . '/robots.txt'));
        self::assertSame('not in any manifest', file_get_contents($this->target . '/_nuxt/custom.js'));
        self::assertSame('0.11.0', HostedBundle::verify($this->target, '0.11.0')->version);
    }

    public function testReinstallingTheSameBuildPrunesNothing(): void
    {
        $this->install('0.11.0');
        $before = self::snapshot($this->target);
        $this->install('0.11.0');

        self::assertSame($before, self::snapshot($this->target));
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
