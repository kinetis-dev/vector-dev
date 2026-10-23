<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Ui\HostedBundle;

/**
 * Builds what the hosted-UI tests install: bundle directories with the
 * manifest the build script writes, tar archives byte by byte (so a test
 * can craft any header a real tar tool would refuse to write), and npm
 * tarballs of a bundle.
 */
final class UiFixtures
{
    public const string SOURCE = '0123456789abcdef0123456789abcdef01234567';

    /** A new empty directory under the system's temporary one. */
    public static function tempDir(string $purpose): string
    {
        $dir = sys_get_temp_dir() . "/vector-test-{$purpose}-" . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);

        return $dir;
    }

    public static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove("{$path}/{$entry}");
                }
            }

            rmdir($path);

            return;
        }

        if (is_link($path) || file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Writes $files into $dir and the manifest describing them, with
     * $overrides applied on top.
     *
     * @param array<string, string> $files relative path => contents
     * @param array<string, mixed> $overrides
     */
    public static function bundle(string $dir, string $version, array $files = [], array $overrides = []): void
    {
        $files = $files === [] ? self::files($version) : $files;
        $hashes = [];

        foreach ($files as $path => $contents) {
            if (!is_dir(dirname("{$dir}/{$path}"))) {
                mkdir(dirname("{$dir}/{$path}"), 0755, true);
            }

            file_put_contents("{$dir}/{$path}", $contents);
            $hashes[$path] = hash('sha256', $contents);
        }

        ksort($hashes, SORT_STRING);

        file_put_contents("{$dir}/" . HostedBundle::MANIFEST, json_encode([
            'package' => '@kinetis/vector-ui',
            'version' => $version,
            'descriptorVersion' => VectorRegistry::VERSION,
            'source' => self::SOURCE,
            'files' => $hashes,
            ...$overrides,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The files of a build of $version: a shell and assets whose names,
     * like Nuxt's hashed chunks, differ from one version to the next.
     *
     * @return array<string, string>
     */
    public static function files(string $version): array
    {
        $tag = substr(hash('sha256', $version), 0, 8);

        return [
            'index.html' => "<!doctype html><title>Vector {$version}</title><script src=\"/vector/ui/_nuxt/entry.{$tag}.js\"></script>",
            "_nuxt/entry.{$tag}.js" => "console.log('{$version}')",
            '_nuxt/shared.css' => 'body{}',
        ];
    }

    /** One tar header, its checksum computed; $name and $prefix are cut to their fields. */
    public static function header(string $name, string $type = '0', int $size = 0, string $linkname = '', string $prefix = ''): string
    {
        $header = str_pad(substr($name, 0, 100), 100, "\0")
            . "0000644\0" . "0000000\0" . "0000000\0"
            . str_pad(decoct($size), 11, '0', STR_PAD_LEFT) . "\0"
            . "00000000000\0"
            . '        '
            . $type
            . str_pad(substr($linkname, 0, 100), 100, "\0")
            . "ustar\0" . '00'
            . str_repeat("\0", 80)
            . str_pad(substr($prefix, 0, 155), 155, "\0")
            . str_repeat("\0", 12);
        $sum = array_sum(array_map(ord(...), str_split($header)));

        return substr_replace($header, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT) . "\0 ", 148, 8);
    }

    /** One entry: its header, its data and the data's padding. */
    public static function entry(string $name, string $data = '', string $type = '0', string $linkname = '', string $prefix = ''): string
    {
        return self::header($name, $type, strlen($data), $linkname, $prefix) . self::pad($data);
    }

    /**
     * A pax extended header (`x`) carrying $records.
     *
     * @param array<string, string> $records
     */
    public static function pax(array $records): string
    {
        $data = '';

        foreach ($records as $key => $value) {
            $body = " {$key}={$value}\n";
            $length = strlen($body) + 1;

            while (strlen((string) $length) + strlen($body) !== $length) {
                $length = strlen((string) $length) + strlen($body);
            }

            $data .= $length . $body;
        }

        return self::entry('PaxHeader', $data, 'x');
    }

    public static function pad(string $data): string
    {
        return $data . str_repeat("\0", (512 - strlen($data) % 512) % 512);
    }

    /** The end-of-archive marker. */
    public static function end(): string
    {
        return str_repeat("\0", 1024);
    }

    /** Writes $tar gzip-compressed to $path. */
    public static function gzip(string $path, string $tar): string
    {
        file_put_contents($path, (string) gzencode($tar));

        return $path;
    }

    /**
     * Writes a gzip file to $path from what $produce emits, compressing as
     * it goes, so an archive of hundreds of MiB never sits in memory.
     *
     * @param \Closure(\Closure(string): void): void $produce
     */
    public static function streamGzip(string $path, \Closure $produce): string
    {
        $out = fopen($path, 'wb');
        $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 1]);
        assert($out !== false && $deflate !== false);

        $produce(static function (string $bytes) use ($out, $deflate): void {
            fwrite($out, (string) deflate_add($deflate, $bytes, ZLIB_NO_FLUSH));
        });
        fwrite($out, (string) deflate_add($deflate, '', ZLIB_FINISH));
        fclose($out);

        return $path;
    }

    /** Emits $size zero bytes, padded to the block, in chunks. */
    public static function zeros(\Closure $emit, int $size): void
    {
        $chunk = str_repeat("\0", 1 << 20);

        for ($left = $size; $left > 0; $left -= strlen($chunk)) {
            $emit(substr($chunk, 0, min($left, strlen($chunk))));
        }

        $emit(str_repeat("\0", (512 - $size % 512) % 512));
    }

    /**
     * An npm tarball of the bundle in $bundleDir: its files under
     * package/dist/static/, beside a package.json, as `npm pack` lays it out.
     */
    public static function npmTarball(string $bundleDir, string $path): string
    {
        $tar = self::entry('package/package.json', '{"name":"@kinetis/vector-ui"}');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($bundleDir, \FilesystemIterator::SKIP_DOTS));
        $files = [];

        foreach ($iterator as $item) {
            $files[] = substr((string) $item->getPathname(), strlen($bundleDir) + 1);
        }

        sort($files);

        foreach ($files as $file) {
            $tar .= self::entry("package/dist/static/{$file}", (string) file_get_contents("{$bundleDir}/{$file}"));
        }

        return self::gzip($path, $tar . self::end());
    }

    /** The SRI string npm publishes for a file: sha512, base64. */
    public static function integrity(string $file): string
    {
        return 'sha512-' . base64_encode((string) hash_file('sha512', $file, true));
    }
}
