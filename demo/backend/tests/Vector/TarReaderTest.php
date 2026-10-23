<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Vector\Ui\TarReader;
use PHPUnit\Framework\TestCase;

/**
 * TarReader against archives crafted header by header: only files and
 * directories under package/dist/static/ are written; every other entry
 * type, every path that could land outside the stage, a repeated path and
 * an archive past any of the fixed limits is refused. Long names in pax
 * and GNU form are read.
 */
final class TarReaderTest extends TestCase
{
    private string $work;

    private string $stage;

    protected function setUp(): void
    {
        $this->work = UiFixtures::tempDir('tar');
        $this->stage = $this->work . '/stage';
        mkdir($this->stage);
    }

    protected function tearDown(): void
    {
        UiFixtures::remove($this->work);
    }

    /**
     * @return list<string>
     */
    private function extract(string $tar): array
    {
        return new TarReader()->extract(UiFixtures::gzip($this->work . '/ui.tgz', $tar), $this->stage);
    }

    private function refuses(string $tar, string $message): void
    {
        try {
            $this->extract($tar);
            self::fail('The archive was accepted.');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    /**
     * What the stage holds, relative paths.
     *
     * @return list<string>
     */
    private function staged(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->stage, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $item) {
            $files[] = substr((string) $item->getPathname(), strlen($this->stage) + 1);
        }

        sort($files);

        return $files;
    }

    public function testOnlyTheBundleUnderPackageDistStaticIsWritten(): void
    {
        $written = $this->extract(
            UiFixtures::entry('package/', '', '5')
            . UiFixtures::entry('package/package.json', '{}')
            . UiFixtures::entry('package/app/app.vue', '<template />')
            . UiFixtures::entry('package/dist/static/', '', '5')
            . UiFixtures::entry('package/dist/static/index.html', '<!doctype html>')
            . UiFixtures::entry('package/dist/static/_nuxt/', '', '5')
            . UiFixtures::entry('package/dist/static/_nuxt/entry.js', str_repeat('x', 700))
            . UiFixtures::end(),
        );

        self::assertSame(['index.html', '_nuxt/entry.js'], $written);
        self::assertSame(['_nuxt/entry.js', 'index.html'], $this->staged());
        self::assertSame(str_repeat('x', 700), file_get_contents($this->stage . '/_nuxt/entry.js'));
        self::assertSame(0644, fileperms($this->stage . '/index.html') & 0777, 'tar modes are ignored');
        self::assertSame(0755, fileperms($this->stage . '/_nuxt') & 0777);
    }

    public function testAPathOutsidePackageDistStaticIsNotExtracted(): void
    {
        $this->extract(UiFixtures::entry('package/dist/other/index.html', 'x') . UiFixtures::entry('package/dist/staticx/a.js', 'x') . UiFixtures::end());

        self::assertSame([], $this->staged());
    }

    public function testAPathOutsidePackageIsRefused(): void
    {
        $this->refuses(UiFixtures::entry('elsewhere/dist/static/index.html', 'x') . UiFixtures::end(), 'is outside package/');
    }

    public function testASymlinkIsRefused(): void
    {
        $this->refuses(UiFixtures::entry('package/dist/static/index.html', '', '2', '/etc/passwd') . UiFixtures::end(), 'is a symbolic link');
        self::assertSame([], $this->staged());
    }

    public function testAHardlinkIsRefused(): void
    {
        $this->refuses(
            UiFixtures::entry('package/dist/static/a.js', 'x')
            . UiFixtures::entry('package/dist/static/b.js', '', '1', 'package/dist/static/a.js')
            . UiFixtures::end(),
            'is a hard link',
        );
    }

    public function testADeviceIsRefused(): void
    {
        $this->refuses(UiFixtures::entry('package/dist/static/tty', '', '3') . UiFixtures::end(), 'is a character device');
        $this->refuses(UiFixtures::entry('package/dist/static/disk', '', '4') . UiFixtures::end(), 'is a block device');
        $this->refuses(UiFixtures::entry('package/dist/static/fifo', '', '6') . UiFixtures::end(), 'is a FIFO');
    }

    public function testADotDotSegmentIsRefused(): void
    {
        $this->refuses(UiFixtures::entry('package/dist/static/../../../escape.js', 'x') . UiFixtures::end(), 'climbs out with ".."');
        self::assertFileDoesNotExist($this->work . '/escape.js');
    }

    public function testAnAbsolutePathIsRefused(): void
    {
        $this->refuses(UiFixtures::entry('/package/dist/static/index.html', 'x') . UiFixtures::end(), 'is an absolute path');
    }

    public function testABackslashIsRefused(): void
    {
        $this->refuses(UiFixtures::entry('package/dist/static/..\\..\\escape.js', 'x') . UiFixtures::end(), 'holds a backslash');
    }

    public function testANulInALongNameIsRefused(): void
    {
        $this->refuses(UiFixtures::entry('././@LongLink', "package/dist/static/a.js\0../x", 'L') . UiFixtures::entry('ignored', 'x') . UiFixtures::end(), 'holds a NUL byte');
    }

    public function testADuplicatePathIsRefused(): void
    {
        $this->refuses(
            UiFixtures::entry('package/dist/static/index.html', 'first')
            . UiFixtures::entry('package/dist/static/index.html', 'second')
            . UiFixtures::end(),
            'lists "package/dist/static/index.html" twice',
        );
        self::assertSame('first', file_get_contents($this->stage . '/index.html'), 'never overwritten');
    }

    public function testAPaxPathIsRead(): void
    {
        $long = 'package/dist/static/_nuxt/' . str_repeat('a', 120) . '.js';

        $written = $this->extract(UiFixtures::pax(['path' => $long, 'mtime' => '1700000000']) . UiFixtures::entry('truncated-name', 'pax') . UiFixtures::end());

        self::assertSame(['_nuxt/' . str_repeat('a', 120) . '.js'], $written);
        self::assertSame('pax', file_get_contents($this->stage . '/_nuxt/' . str_repeat('a', 120) . '.js'));
    }

    public function testAPaxPathIsCheckedLikeAnyOther(): void
    {
        $this->refuses(UiFixtures::pax(['path' => 'package/dist/static/../../x.js']) . UiFixtures::entry('package/dist/static/fine.js', 'x') . UiFixtures::end(), 'climbs out');
    }

    public function testAGnuLongNameIsRead(): void
    {
        $long = 'package/dist/static/_nuxt/' . str_repeat('b', 150) . '.js';

        $written = $this->extract(UiFixtures::entry('././@LongLink', $long . "\0", 'L') . UiFixtures::entry('truncated-name', 'gnu') . UiFixtures::end());

        self::assertSame(['_nuxt/' . str_repeat('b', 150) . '.js'], $written);
    }

    public function testAUstarPrefixIsRead(): void
    {
        $written = $this->extract(UiFixtures::entry('index.html', 'prefixed', '0', '', 'package/dist/static') . UiFixtures::end());

        self::assertSame(['index.html'], $written);
    }

    public function testAWrongChecksumIsRefused(): void
    {
        $entry = UiFixtures::entry('package/dist/static/index.html', 'x');
        $entry[0] = 'q';

        $this->refuses($entry . UiFixtures::end(), 'wrong checksum');
    }

    public function testAnEntryOverThePerEntryLimitIsRefused(): void
    {
        // the header alone: the size is refused before a byte of data is read
        $this->refuses(UiFixtures::header('package/dist/static/big.js', '0', TarReader::MAX_ENTRY + 1), 'big.js is larger than ' . TarReader::MAX_ENTRY . ' bytes');
        self::assertSame([], $this->staged());
    }

    public function testAnArchiveOverTheTotalLimitIsRefused(): void
    {
        $size = 15 * 1024 * 1024;
        $archive = UiFixtures::streamGzip($this->work . '/total.tgz', static function (\Closure $emit) use ($size): void {
            for ($i = 0; $i < 9; $i++) {
                $emit(UiFixtures::header("package/dist/static/part{$i}.bin", '0', $size));
                UiFixtures::zeros($emit, $size);
            }

            $emit(UiFixtures::end());
        });

        try {
            new TarReader()->extract($archive, $this->stage);
            self::fail('The archive was accepted.');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('extracts to more than ' . TarReader::MAX_TOTAL . ' bytes', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->stage . '/part8.bin');
    }

    public function testAnArchiveOverTheEntryCountIsRefused(): void
    {
        $tar = '';

        for ($i = 0; $i <= TarReader::MAX_ENTRIES; $i++) {
            $tar .= UiFixtures::header("package/dist/static/d{$i}/", '5');
        }

        $this->refuses($tar . UiFixtures::end(), 'more than ' . TarReader::MAX_ENTRIES . ' entries');
    }

    public function testAGzipBombIsRefusedAtTheDecompressionCap(): void
    {
        // entries read past rather than written, so only the decompression cap stands in the way
        $size = 15 * 1024 * 1024;
        $archive = UiFixtures::streamGzip($this->work . '/bomb.tgz', static function (\Closure $emit) use ($size): void {
            for ($i = 0; $i < 12; $i++) {
                $emit(UiFixtures::header("package/padding{$i}.bin", '0', $size));
                UiFixtures::zeros($emit, $size);
            }

            $emit(UiFixtures::end());
        });

        self::assertLessThan(1024 * 1024, filesize($archive), 'a small download');

        try {
            new TarReader()->extract($archive, $this->stage);
            self::fail('The archive was accepted.');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('decompresses to more than ' . TarReader::MAX_DECOMPRESSED . ' bytes', $e->getMessage());
        }
    }

    public function testDataThatIsNotGzipIsRefused(): void
    {
        file_put_contents($this->work . '/plain.tgz', UiFixtures::entry('package/dist/static/index.html', 'x') . UiFixtures::end());

        $this->expectExceptionMessage('not valid gzip');
        new TarReader()->extract($this->work . '/plain.tgz', $this->stage);
    }

    public function testATruncatedArchiveIsRefused(): void
    {
        $this->refuses(substr(UiFixtures::entry('package/dist/static/index.html', str_repeat('x', 600)), 0, 700), 'ends inside');
    }
}
