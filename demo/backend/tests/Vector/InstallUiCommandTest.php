<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
use Kinetis\Vector\Console\InstallUiCommand;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Ui\HostedBundle;
use Kinetis\Vector\Ui\HttpsTarballSource;
use Kinetis\Vector\Ui\UiTarballSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * vector:install-ui, every flag and every exit code, against a fake
 * registry: 0 installed or in sync, 1 refused or failed — with nothing
 * written into the served directory — and 2 a usage error. The token
 * scoping runs through HttpsTarballSource itself, on a fake transport.
 */
final class InstallUiCommandTest extends TestCase
{
    private const string TOKEN = 'npm_SECRET-token-0123456789';

    private string $work;

    private string $target;

    /** @var resource */
    private $output;

    /** @var resource */
    private $errors;

    protected function setUp(): void
    {
        $this->work = UiFixtures::tempDir('install-ui');
        $this->target = $this->work . '/public/vector/ui';
        $this->output = fopen('php://memory', 'w+') ?: throw new \RuntimeException('No memory stream.');
        $this->errors = fopen('php://memory', 'w+') ?: throw new \RuntimeException('No memory stream.');
        file_put_contents($this->work . '/ui.json', '{"package":"@kinetis/vector-ui","version":"0.11.0"}');
    }

    protected function tearDown(): void
    {
        UiFixtures::remove($this->work);
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $config
     */
    private function command(array $argv, ?UiTarballSource $source = null, array $config = [], bool $devInstall = false): int
    {
        return new InstallUiCommand($this->output, $this->errors, $source)
            ->run(CommandArguments::parse($argv), new Config($config), $this->work, $this->work . '/ui.json', $devInstall);
    }

    private function stdout(): string
    {
        rewind($this->output);

        return (string) stream_get_contents($this->output);
    }

    private function stderr(): string
    {
        rewind($this->errors);

        return (string) stream_get_contents($this->errors);
    }

    /**
     * A built bundle of $version, as a dist/static directory.
     *
     * @param array<string, mixed> $overrides
     */
    private function built(string $version, array $overrides = []): string
    {
        $dir = $this->work . '/built-' . bin2hex(random_bytes(4));
        mkdir($dir);
        UiFixtures::bundle($dir, $version, [], $overrides);

        return $dir;
    }

    /**
     * An npm tarball of a build of $version.
     *
     * @param array<string, mixed> $overrides
     */
    private function tarball(string $version, array $overrides = []): string
    {
        return UiFixtures::npmTarball($this->built($version, $overrides), $this->work . "/vector-ui-{$version}-" . bin2hex(random_bytes(4)) . '.tgz');
    }

    private function registry(string $version = '0.11.0'): FakeTarballSource
    {
        $archive = $this->tarball($version);

        return new FakeTarballSource(integrity: UiFixtures::integrity($archive), archive: $archive);
    }

    private function assertNothingInstalled(): void
    {
        self::assertDirectoryDoesNotExist($this->target);
    }

    public function testThePinnedVersionIsInstalledFromTheRegistry(): void
    {
        $source = $this->registry();

        self::assertSame(0, $this->command([], $source), $this->stderr());

        self::assertSame(['resolve @kinetis/vector-ui@0.11.0', 'download https://registry.example/@kinetis/vector-ui/-/vector-ui.tgz'], $source->calls);
        self::assertStringContainsString("Installed @kinetis/vector-ui 0.11.0 (3 files) into {$this->target}.", $this->stdout());
        self::assertStringContainsString('Restart workers', $this->stdout());
        self::assertSame('0.11.0', HostedBundle::verify($this->target, '0.11.0')->version);
    }

    public function testVersionInstallsAnotherPublishedVersion(): void
    {
        $source = $this->registry('0.12.0');

        self::assertSame(0, $this->command(['--version=0.12.0'], $source), $this->stderr());

        self::assertSame('resolve @kinetis/vector-ui@0.12.0', $source->calls[0]);
        self::assertSame('0.12.0', HostedBundle::verify($this->target, '0.12.0')->version);
    }

    public function testATarballOfAnotherVersionThanAskedIsRefused(): void
    {
        $source = $this->registry('0.12.0');

        self::assertSame(1, $this->command([], $source));

        self::assertStringContainsString('it is version 0.12.0; 0.11.0 is expected', $this->stderr());
        $this->assertNothingInstalled();
    }

    public function testAnHttpRegistryIsRefusedBeforeItIsAsked(): void
    {
        $source = $this->registry();

        self::assertSame(1, $this->command([], $source, ['VECTOR_UI_REGISTRY' => 'http://registry.example']));

        self::assertStringContainsString('Refusing VECTOR_UI_REGISTRY: it must be an https URL', $this->stderr());
        self::assertSame([], $source->calls);
        $this->assertNothingInstalled();
    }

    public function testARegistryUrlWithCredentialsIsRefusedWithoutPrintingThem(): void
    {
        self::assertSame(1, $this->command([], $this->registry(), ['VECTOR_UI_REGISTRY' => 'https://user:hunter2@registry.example']));

        self::assertStringNotContainsString('hunter2', $this->stderr() . $this->stdout());
    }

    public function testAnHttpTarballIsRefusedBeforeItIsDownloaded(): void
    {
        $source = $this->registry();
        $source->tarball = 'http://registry.example/@kinetis/vector-ui/-/vector-ui-0.11.0.tgz';

        self::assertSame(1, $this->command([], $source));

        self::assertStringContainsString('it is not an https URL', $this->stderr());
        self::assertSame(['resolve @kinetis/vector-ui@0.11.0'], $source->calls);
        $this->assertNothingInstalled();
    }

    public function testAVersionWithoutDistIntegrityIsRefused(): void
    {
        $source = $this->registry();
        $source->integrity = null;

        self::assertSame(1, $this->command([], $source));

        self::assertStringContainsString('the registry gives no sha512 dist.integrity', $this->stderr());
        self::assertSame(['resolve @kinetis/vector-ui@0.11.0'], $source->calls);
        $this->assertNothingInstalled();
    }

    public function testAnSha1OnlyIntegrityIsRefused(): void
    {
        $source = $this->registry();
        $source->integrity = 'sha1-' . base64_encode(sha1('x', true));

        self::assertSame(1, $this->command([], $source));

        self::assertStringContainsString('no sha512 dist.integrity', $this->stderr());
        $this->assertNothingInstalled();
    }

    public function testATarballThatDoesNotMatchItsIntegrityIsRefusedBeforeExtraction(): void
    {
        $source = $this->registry();
        $source->integrity = UiFixtures::integrity($this->tarball('0.11.0', ['source' => str_repeat('f', 40)]));

        self::assertSame(1, $this->command([], $source));

        self::assertStringContainsString('its sha512 does not match the integrity', $this->stderr());
        $this->assertNothingInstalled();
    }

    public function testAnOversizedDownloadIsRefused(): void
    {
        $source = $this->registry();
        $big = $this->work . '/big.tgz';
        $handle = fopen($big, 'w');
        self::assertNotFalse($handle);
        ftruncate($handle, 32 * 1024 * 1024 + 1);
        fclose($handle);
        $source->archive = $big;
        $source->integrity = UiFixtures::integrity($big);

        self::assertSame(1, $this->command([], $source));

        self::assertStringContainsString('larger than ' . 32 * 1024 * 1024 . ' bytes', $this->stderr());
        $this->assertNothingInstalled();
    }

    public function testFromInstallsATarballCheckedAgainstItsIntegrity(): void
    {
        $archive = $this->tarball('0.11.0');

        self::assertSame(0, $this->command(["--from={$archive}", '--integrity=' . UiFixtures::integrity($archive)]), $this->stderr());
        self::assertSame('0.11.0', HostedBundle::verify($this->target, '0.11.0')->version);
    }

    public function testFromRefusesATarballThatDoesNotMatchTheGivenIntegrity(): void
    {
        $archive = $this->tarball('0.11.0');

        self::assertSame(1, $this->command(["--from={$archive}", '--integrity=' . UiFixtures::integrity($this->tarball('0.10.0'))]));

        self::assertStringContainsString('its sha512 does not match', $this->stderr());
        $this->assertNothingInstalled();
    }

    public function testFromInstallsATarballWithoutIntegrityAndTouchesNoRegistry(): void
    {
        $source = new FakeTarballSource();

        self::assertSame(0, $this->command(['--from=' . $this->tarball('0.11.0')], $source, ['VECTOR_UI_REGISTRY' => 'http://not-used.example']), $this->stderr());
        self::assertSame([], $source->calls);
    }

    public function testFromInstallsABuiltDirectory(): void
    {
        $built = $this->built('0.11.0');

        self::assertSame(0, $this->command(["--from={$built}"]), $this->stderr());
        self::assertSame('0.11.0', HostedBundle::verify($this->target, '0.11.0')->version);
        self::assertFileExists($built . '/index.html', 'the source is copied, not moved');
    }

    public function testIntegrityWithABuiltDirectoryIsAUsageError(): void
    {
        $built = $this->built('0.11.0');
        $source = $this->registry();

        self::assertSame(2, $this->command(["--from={$built}", '--integrity=sha512-' . base64_encode(str_repeat('a', 64))], $source));

        self::assertStringContainsString('--from names a directory', $this->stderr());
        self::assertStringContainsString('Usage: vector:install-ui', $this->stderr());
        self::assertSame([], $source->calls);
        $this->assertNothingInstalled();
    }

    public function testFromRefusesADirectoryHoldingASymlink(): void
    {
        $built = $this->built('0.11.0');
        symlink('/etc/hosts', $built . '/hosts');

        self::assertSame(1, $this->command(["--from={$built}"]));
        self::assertStringContainsString('neither a file nor a directory', $this->stderr());
        $this->assertNothingInstalled();
    }

    public function testFromStillRequiresThePinnedVersion(): void
    {
        self::assertSame(1, $this->command(['--from=' . $this->built('0.10.0')]));
        self::assertStringContainsString('it is version 0.10.0; 0.11.0 is expected', $this->stderr());
        $this->assertNothingInstalled();
    }

    public function testFromWithVersionTakesThatVersion(): void
    {
        self::assertSame(0, $this->command(['--from=' . $this->built('0.10.0'), '--version=0.10.0']), $this->stderr());
        self::assertSame('0.10.0', HostedBundle::verify($this->target, '0.10.0')->version);
    }

    public function testFromRefusesAnotherDescriptorVersion(): void
    {
        self::assertSame(1, $this->command(['--from=' . $this->built('0.11.0', ['descriptorVersion' => VectorRegistry::VERSION + 1])]));
        self::assertStringContainsString('it speaks descriptor version ' . (VectorRegistry::VERSION + 1), $this->stderr());
        $this->assertNothingInstalled();
    }

    public function testFromRefusesWhatIsNeitherATarballNorADirectory(): void
    {
        file_put_contents($this->work . '/bundle.zip', 'PK');

        self::assertSame(1, $this->command(['--from=' . $this->work . '/bundle.zip']));
        self::assertStringContainsString('is neither a .tgz tarball nor a directory', $this->stderr());
    }

    public function testADevBuildIsAcceptedOnlyOnADevInstall(): void
    {
        $built = $this->built('dev-0123456789ab');

        self::assertSame(1, $this->command(["--from={$built}"], devInstall: false));
        self::assertStringContainsString('it is version dev-0123456789ab; 0.11.0 is expected', $this->stderr());
        $this->assertNothingInstalled();

        self::assertSame(0, $this->command(["--from={$built}"], devInstall: true), $this->stderr());
        self::assertSame('dev-0123456789ab', HostedBundle::verify($this->target, null)->version);
        self::assertSame(0, $this->command(['--check'], devInstall: true), 'in sync on a dev install');
        self::assertSame(1, $this->command(['--check'], devInstall: false), 'not on a release install');
    }

    public function testWithoutAPinAVersionMustBeNamed(): void
    {
        unlink($this->work . '/ui.json');

        self::assertSame(1, $this->command([], $this->registry()));
        self::assertStringContainsString('No UI version is pinned', $this->stderr());

        self::assertSame(0, $this->command(['--version=0.11.0'], $this->registry()), $this->stderr());
    }

    public function testCheckIsZeroWhenInSync(): void
    {
        self::assertSame(0, $this->command([], $this->registry()));

        self::assertSame(0, $this->command(['--check']));
        self::assertStringContainsString('in sync', $this->stdout());
    }

    public function testCheckIsOneWithoutAManifest(): void
    {
        mkdir($this->target, 0755, true);
        file_put_contents($this->target . '/index.html', '<!doctype html>');

        self::assertSame(1, $this->command(['--check']));
        self::assertStringContainsString('cannot verify: no vector-bundle.json', $this->stderr());
    }

    public function testCheckIsOneOnAVersionMismatch(): void
    {
        self::assertSame(0, $this->command([], $this->registry()));

        self::assertSame(1, $this->command(['--check', '--version=0.12.0']));
        self::assertStringContainsString('it is version 0.11.0; 0.12.0 is expected', $this->stderr());
    }

    public function testCheckIsOneWhenAFileChanged(): void
    {
        self::assertSame(0, $this->command([], $this->registry()));
        file_put_contents($this->target . '/_nuxt/shared.css', 'tampered');

        self::assertSame(1, $this->command(['--check']));
    }

    public function testCheckTouchesNoRegistry(): void
    {
        $source = $this->registry();

        $this->command(['--check'], $source, ['VECTOR_UI_REGISTRY' => 'http://registry.example']);

        self::assertSame([], $source->calls);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function usageErrors(): iterable
    {
        yield '--check with --force' => [['--check', '--force'], '--check takes no option but --version'];
        yield '--check with --from' => [['--check', '--from=/tmp/x'], '--check takes no option but --version'];
        yield '--check with --integrity' => [['--check', '--from=/tmp/x', '--integrity=sha512-' . base64_encode(str_repeat('a', 64))], '--check takes no option but --version'];
        yield '--check with a value' => [['--check=yes'], '--check takes no value'];
        yield '--check with a positional argument' => [['--check', '0.11.0'], 'takes no positional argument'];
        yield 'an inexact --version' => [['--version=^0.11'], 'is not an exact X.Y.Z version'];
        yield 'an empty --version' => [['--version='], '--version needs a value'];
        yield '--from without a value' => [['--from'], '--from needs a value'];
        yield '--integrity without --from' => [['--integrity=sha512-' . base64_encode(str_repeat('a', 64))], 'give --from=<tgz> with it'];
        yield 'an integrity that is not sha512' => [['--from=/tmp/x.tgz', '--integrity=sha1-abc'], '--integrity needs a sha512 SRI string'];
        yield '--force with a value' => [['--force=1'], '--force takes no value'];
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('usageErrors')]
    public function testAUsageErrorIsTwo(array $argv, string $message): void
    {
        $source = $this->registry();

        self::assertSame(2, $this->command($argv, $source));

        self::assertStringContainsString($message, $this->stderr());
        self::assertStringContainsString('Usage: vector:install-ui', $this->stderr());
        self::assertSame([], $source->calls);
        $this->assertNothingInstalled();
    }

    /**
     * A registry on one host serving its tarballs from another, through
     * HttpsTarballSource and a transport that records every request.
     *
     * @param array<string, array{int, array<string, string>, string}> $responses url => status, headers, body
     * @param list<array{string, list<string>}> $requests
     */
    private function httpsSource(array $responses, array &$requests): HttpsTarballSource
    {
        return new HttpsTarballSource('https://registry.example/', self::TOKEN, static function (string $url, array $headers) use ($responses, &$requests): array {
            $requests[] = [$url, $headers];
            [$status, $responseHeaders, $body] = $responses[$url] ?? [404, [], 'Not found'];
            $stream = fopen('php://memory', 'w+');
            assert($stream !== false);
            fwrite($stream, $body);
            rewind($stream);

            return [$status, $responseHeaders, $stream];
        });
    }

    private static function packument(string $tarball, ?string $integrity): string
    {
        return json_encode(['name' => '@kinetis/vector-ui', 'versions' => ['0.11.0' => ['dist' => ['tarball' => $tarball, ...($integrity === null ? [] : ['integrity' => $integrity])]]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function testTheTokenGoesToTheRegistryHostOnlyAndIsNeverPrinted(): void
    {
        $archive = $this->tarball('0.11.0');
        $requests = [];
        $source = $this->httpsSource([
            'https://registry.example/@kinetis%2Fvector-ui' => [200, [], self::packument('https://registry.example/@kinetis/vector-ui/-/vector-ui-0.11.0.tgz', UiFixtures::integrity($archive))],
            // the registry hands the tarball to a CDN on another host
            'https://registry.example/@kinetis/vector-ui/-/vector-ui-0.11.0.tgz' => [302, ['location' => 'https://cdn.example/t/vector-ui-0.11.0.tgz'], ''],
            'https://cdn.example/t/vector-ui-0.11.0.tgz' => [200, [], (string) file_get_contents($archive)],
        ], $requests);

        self::assertSame(0, $this->command([], $source, ['VECTOR_UI_REGISTRY' => 'https://registry.example', 'VECTOR_UI_REGISTRY_TOKEN' => self::TOKEN]), $this->stderr());

        $authorized = array_map(static fn (array $request): bool => in_array('Authorization: Bearer ' . self::TOKEN, $request[1], true), $requests);
        self::assertSame([
            'https://registry.example/@kinetis%2Fvector-ui',
            'https://registry.example/@kinetis/vector-ui/-/vector-ui-0.11.0.tgz',
            'https://cdn.example/t/vector-ui-0.11.0.tgz',
        ], array_column($requests, 0));
        self::assertSame([true, true, false], $authorized);
        self::assertContains('Accept: application/vnd.npm.install-v1+json', $requests[0][1]);
        self::assertStringNotContainsString(self::TOKEN, $this->stdout() . $this->stderr());
    }

    public function testATokenNeverReachesATarballOnAnotherHostAndFailuresDoNotPrintIt(): void
    {
        $requests = [];
        $source = $this->httpsSource([
            'https://registry.example/@kinetis%2Fvector-ui' => [200, [], self::packument('https://cdn.example/vector-ui-0.11.0.tgz?sig=' . self::TOKEN, UiFixtures::integrity($this->tarball('0.11.0')))],
            'https://cdn.example/vector-ui-0.11.0.tgz?sig=' . self::TOKEN => [403, [], 'Forbidden: ' . self::TOKEN],
        ], $requests);

        self::assertSame(1, $this->command([], $source, ['VECTOR_UI_REGISTRY' => 'https://registry.example', 'VECTOR_UI_REGISTRY_TOKEN' => self::TOKEN]));

        self::assertCount(2, $requests);
        self::assertNotContains('Authorization: Bearer ' . self::TOKEN, $requests[1][1]);
        self::assertStringContainsString('answered HTTP 403', $this->stderr());
        self::assertStringNotContainsString(self::TOKEN, $this->stdout() . $this->stderr());
        $this->assertNothingInstalled();
    }

    public function testARedirectToHttpIsRefused(): void
    {
        $requests = [];
        $source = $this->httpsSource([
            'https://registry.example/@kinetis%2Fvector-ui' => [301, ['location' => 'http://registry.example/@kinetis%2Fvector-ui'], ''],
        ], $requests);

        self::assertSame(1, $this->command([], $source, ['VECTOR_UI_REGISTRY' => 'https://registry.example', 'VECTOR_UI_REGISTRY_TOKEN' => self::TOKEN]));

        self::assertCount(1, $requests, 'the http URL is never requested');
        self::assertStringContainsString('only https URLs are fetched', $this->stderr());
    }

    public function testAMissingIntegrityInThePackumentIsRefused(): void
    {
        $requests = [];
        $source = $this->httpsSource([
            'https://registry.example/@kinetis%2Fvector-ui' => [200, [], self::packument('https://registry.example/x.tgz', null)],
        ], $requests);

        self::assertSame(1, $this->command([], $source, ['VECTOR_UI_REGISTRY' => 'https://registry.example']));

        self::assertStringContainsString('no sha512 dist.integrity', $this->stderr());
        self::assertCount(1, $requests, 'nothing downloaded');
    }

    public function testATokenThatCouldAddAHeaderIsRefusedWithoutPrintingIt(): void
    {
        $token = self::TOKEN . "\r\nX-Injected: yes";

        try {
            new HttpsTarballSource('https://registry.example', $token);
            self::fail('The token was accepted.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testAnHttpRegistryCannotBuildASource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HttpsTarballSource('http://registry.example', null);
    }
}
