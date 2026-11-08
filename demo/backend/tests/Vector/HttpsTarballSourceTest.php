<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Vector\Ui\HttpsTarballSource;
use Kinetis\Vector\Ui\UiInstallException;
use PHPUnit\Framework\TestCase;

/**
 * HttpsTarballSource's fetch on a fake transport: a redirect to a path
 * stays on the origin, and every way the registry can fail — a status
 * other than 200, too many redirects, a redirect without a Location, a
 * body over the cap — is a UiInstallException, which is a
 * \RuntimeException, with the URL shown without its query.
 */
final class HttpsTarballSourceTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = UiFixtures::tempDir('https');
    }

    protected function tearDown(): void
    {
        UiFixtures::remove($this->work);
    }

    /**
     * @param array<string, array{int, array<string, string>, string}> $responses url => status, headers, body
     * @param list<string> $requested
     */
    private static function source(array $responses, array &$requested): HttpsTarballSource
    {
        return new HttpsTarballSource('https://registry.example', null, static function (string $url, array $headers) use ($responses, &$requested): array {
            $requested[] = $url;
            [$status, $responseHeaders, $body] = $responses[$url] ?? [404, [], 'Not found'];
            $stream = fopen('php://memory', 'w+');
            assert($stream !== false);
            fwrite($stream, $body);
            rewind($stream);

            return [$status, $responseHeaders, $stream];
        });
    }

    /**
     * @param array<string, array{int, array<string, string>, string}> $responses
     */
    private function downloadFails(array $responses, string $url, string $message, int $maxBytes = 1024): void
    {
        $requested = [];

        try {
            self::source($responses, $requested)->download($url, $this->work . '/ui.tgz', $maxBytes);
            self::fail('The download succeeded.');
        } catch (UiInstallException $e) {
            self::assertSame($message, $e->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $e);
        }
    }

    public function testARedirectToAPathStaysOnTheOrigin(): void
    {
        $requested = [];
        $source = self::source([
            'https://registry.example/a.tgz' => [302, ['location' => '/b.tgz'], ''],
            'https://registry.example:443/b.tgz' => [200, [], 'tarball'],
        ], $requested);

        $source->download('https://registry.example/a.tgz', $this->work . '/ui.tgz', 1024);

        self::assertSame(['https://registry.example/a.tgz', 'https://registry.example:443/b.tgz'], $requested);
        self::assertSame('tarball', file_get_contents($this->work . '/ui.tgz'));
    }

    public function testAStatusOtherThan200IsAnInstallFailure(): void
    {
        $this->downloadFails(
            ['https://registry.example/a.tgz?sig=secret' => [500, [], 'Oops']],
            'https://registry.example/a.tgz?sig=secret',
            '"https://registry.example/a.tgz" answered HTTP 500.',
        );
    }

    public function testTooManyRedirectsAreAnInstallFailure(): void
    {
        $responses = [];

        for ($i = 0; $i <= 5; $i++) {
            $responses["https://registry.example/{$i}"] = [301, ['location' => 'https://registry.example/' . ($i + 1)], ''];
        }

        $this->downloadFails($responses, 'https://registry.example/0', '"https://registry.example/5" redirects more than 5 times.');
    }

    public function testARedirectWithoutALocationIsAnInstallFailure(): void
    {
        $this->downloadFails(
            ['https://registry.example/a.tgz' => [307, [], '']],
            'https://registry.example/a.tgz',
            '"https://registry.example/a.tgz" redirects without a Location.',
        );
    }

    public function testABodyOverTheCapIsAnInstallFailure(): void
    {
        $this->downloadFails(
            ['https://registry.example/a.tgz' => [200, [], str_repeat('x', 11)]],
            'https://registry.example/a.tgz',
            '"https://registry.example/a.tgz" is larger than 10 bytes.',
            10,
        );
    }

    public function testAPackumentWithoutTheVersionIsAnInstallFailure(): void
    {
        $requested = [];
        $source = self::source([
            'https://registry.example/@kinetis%2Fvector-ui' => [200, [], '{"versions":{}}'],
        ], $requested);

        $this->expectException(UiInstallException::class);
        $this->expectExceptionMessage('The registry publishes no @kinetis/vector-ui@0.11.0.');
        $source->resolve('@kinetis/vector-ui', '0.11.0');
    }
}
