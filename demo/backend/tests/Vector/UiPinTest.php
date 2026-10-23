<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Vector\Ui\UiPin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * resources/ui.json: exactly the package and one exact x.y.z version.
 * fromFile() refuses anything else with a reason; tryFromFile() reads a
 * refusal as no expectation, which is what the worker does at boot.
 */
final class UiPinTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = UiFixtures::tempDir('pin');
    }

    protected function tearDown(): void
    {
        UiFixtures::remove($this->dir);
    }

    private function pin(string $contents): string
    {
        file_put_contents($this->dir . '/ui.json', $contents);

        return $this->dir . '/ui.json';
    }

    public function testAValidPinIsRead(): void
    {
        $pin = UiPin::fromFile($this->pin('{"package":"@kinetis/vector-ui","version":"0.11.0"}'));

        self::assertSame('@kinetis/vector-ui', $pin->package);
        self::assertSame('0.11.0', $pin->version);
        self::assertSame('0.11.0', UiPin::tryFromFile($this->dir . '/ui.json')?->version);
    }

    public function testAMissingPinIsNoExpectation(): void
    {
        self::assertNull(UiPin::tryFromFile($this->dir . '/ui.json'));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('is missing');
        UiPin::fromFile($this->dir . '/ui.json');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'not JSON' => ['{"package":'];
        yield 'not an object' => ['["@kinetis/vector-ui","0.11.0"]'];
        yield 'no version' => ['{"package":"@kinetis/vector-ui"}'];
        yield 'another package' => ['{"package":"@acme/vector-ui","version":"0.11.0"}'];
        yield 'the Composer package' => ['{"package":"kinetis/vector","version":"0.11.0"}'];
        yield 'a hash besides' => ['{"package":"@kinetis/vector-ui","version":"0.11.0","integrity":"sha512-x"}'];
        yield 'a number for a version' => ['{"package":"@kinetis/vector-ui","version":11}'];
    }

    #[DataProvider('malformed')]
    public function testAMalformedPinIsRefused(string $contents): void
    {
        $file = $this->pin($contents);

        self::assertNull(UiPin::tryFromFile($file));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('is malformed');
        UiPin::fromFile($file);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inexact(): iterable
    {
        yield 'a range' => ['^0.11.0'];
        yield 'a leading v' => ['v0.11.0'];
        yield 'two components' => ['0.11'];
        yield 'a prerelease' => ['0.11.0-rc.1'];
        yield 'a leading zero' => ['0.011.0'];
        yield 'a dev build' => ['dev-0123456789ab'];
        yield 'a trailing newline' => ["0.11.0\n"];
    }

    #[DataProvider('inexact')]
    public function testANonExactVersionIsRefused(string $version): void
    {
        $file = $this->pin(json_encode(['package' => '@kinetis/vector-ui', 'version' => $version], JSON_THROW_ON_ERROR));

        self::assertNull(UiPin::tryFromFile($file));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('not an exact x.y.z version');
        UiPin::fromFile($file);
    }

    public function testTheShippedPinNamesTheVectorUiPackageVersion(): void
    {
        $package = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/packages/vector-ui/package.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($package);

        self::assertSame($package['version'], UiPin::fromFile(UiPin::path())->version);
    }
}
