<?php

declare(strict_types=1);

namespace Kinetis\Vector\Ui;

/**
 * Extracts the hosted bundle from an npm tarball: the gzip-compressed tar
 * `npm pack` writes, whose files sit under `package/`. Only the entries
 * under `package/dist/static/` are written, relative to that directory;
 * every other `package/*` entry is read past.
 *
 * A streaming reader of its own rather than PharData, so that every byte
 * it accepts is one it chose to: ustar headers, pax extended headers
 * (`x`, and `g`, whose records are read past) and GNU long names (`L`)
 * supply metadata; regular files (`0`, NUL) and directories (`5`) are
 * the only entries written. Links, devices, FIFOs and every other type
 * are refused, as is a path with a `..`, `.` or empty segment, a leading
 * `/`, a backslash or a NUL, and a path seen twice. Tar modes are
 * ignored: files are written 0644, directories 0755.
 *
 * The limits are fixed. Decompression stops at MAX_DECOMPRESSED bytes,
 * so a gzip bomb is refused before it fills the disk, and the archive's
 * own headers are held to MAX_ENTRIES entries, MAX_ENTRY bytes per file
 * and MAX_TOTAL bytes written.
 */
final readonly class TarReader
{
    public const int MAX_DOWNLOAD = 32 * 1024 * 1024;

    public const int MAX_TOTAL = 128 * 1024 * 1024;

    public const int MAX_ENTRY = 16 * 1024 * 1024;

    public const int MAX_ENTRIES = 5000;

    /** Every byte gunzip may produce: the files, plus their headers and the entries read past. */
    public const int MAX_DECOMPRESSED = 160 * 1024 * 1024;

    public const string PREFIX = 'package/dist/static/';

    private const int BLOCK = 512;

    /** A pax header or a GNU long name is metadata: a few KiB, never a file's worth. */
    private const int MAX_METADATA = 64 * 1024;

    /**
     * Extracts the bundle in the gzip-compressed tar $archive into $dest,
     * an existing empty directory.
     *
     * @return list<string> the files written, relative to $dest
     *
     * @throws \UnexpectedValueException when the archive is refused
     * @throws \RuntimeException when a file cannot be read or written
     */
    public function extract(string $archive, string $dest): array
    {
        $in = new GunzipStream($archive, self::MAX_DECOMPRESSED);

        try {
            return $this->entries($in, $dest);
        } finally {
            $in->close();
        }
    }

    /**
     * @return list<string>
     */
    private function entries(GunzipStream $in, string $dest): array
    {
        $written = [];
        $seen = [];
        $entries = 0;
        $total = 0;
        $longName = null;
        $paxPath = null;
        $paxSize = null;

        while (true) {
            $header = $in->read(self::BLOCK);

            if (strlen($header) < self::BLOCK) {
                throw new \UnexpectedValueException('The archive ends inside a header.');
            }

            if ($header === str_repeat("\0", self::BLOCK)) {
                break;
            }

            if (++$entries > self::MAX_ENTRIES) {
                throw new \UnexpectedValueException('The archive has more than ' . self::MAX_ENTRIES . ' entries.');
            }

            self::checksum($header);
            $type = $header[156];
            $size = $paxSize ?? self::octal(substr($header, 124, 12), 'size');

            if ($type === 'x' || $type === 'g' || $type === 'L') {
                if ($longName !== null || $paxPath !== null || $paxSize !== null) {
                    // one metadata header per entry; a second could override what the first was checked for
                    throw new \UnexpectedValueException('The archive stacks metadata headers.');
                }

                if ($size > self::MAX_METADATA) {
                    throw new \UnexpectedValueException('The archive has an oversized metadata header.');
                }

                $data = self::data($in, $size);

                if ($type === 'L') {
                    $longName = rtrim($data, "\0");
                } elseif ($type === 'x') {
                    [$paxPath, $paxSize] = self::pax($data);
                }

                continue;
            }

            $name = $longName ?? $paxPath ?? self::headerName($header);
            $longName = $paxPath = $paxSize = null;

            if ($type !== '0' && $type !== "\0" && $type !== '5') {
                throw new \UnexpectedValueException('The archive entry ' . self::printable($name) . ' is ' . self::typeName($type) . '; only files and directories are accepted.');
            }

            $path = self::path($name, $type === '5');

            if ($type === '5' && $size !== 0) {
                throw new \UnexpectedValueException('The archive directory ' . self::printable($path) . ' carries data.');
            }

            if (isset($seen[$path])) {
                throw new \UnexpectedValueException('The archive lists ' . self::printable($path) . ' twice.');
            }

            $seen[$path] = true;

            if (!str_starts_with($path . '/', self::PREFIX) || $path . '/' === self::PREFIX) {
                if (!str_starts_with($path, 'package/') && $path !== 'package') {
                    throw new \UnexpectedValueException('The archive entry ' . self::printable($path) . ' is outside package/.');
                }

                self::skip($in, $size);

                continue;
            }

            $relative = substr($path, strlen(self::PREFIX));

            if ($type === '5') {
                self::directory("{$dest}/{$relative}");

                continue;
            }

            if ($size > self::MAX_ENTRY) {
                throw new \UnexpectedValueException("The archive file {$relative} is larger than " . self::MAX_ENTRY . ' bytes.');
            }

            $total += $size;

            if ($total > self::MAX_TOTAL) {
                throw new \UnexpectedValueException('The archive extracts to more than ' . self::MAX_TOTAL . ' bytes.');
            }

            self::directory(dirname("{$dest}/{$relative}"));
            self::file($in, "{$dest}/{$relative}", $size);
            $written[] = $relative;
        }

        return $written;
    }

    /**
     * @throws \UnexpectedValueException when the header's checksum is wrong
     */
    private static function checksum(string $header): void
    {
        $stored = self::octal(substr($header, 148, 8), 'checksum');
        $sum = 0;

        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += $i >= 148 && $i < 156 ? 32 : ord($header[$i]);
        }

        if ($sum !== $stored) {
            throw new \UnexpectedValueException('The archive has a header with a wrong checksum.');
        }
    }

    /**
     * A NUL- or space-terminated octal field. The base-256 form only
     * encodes sizes far beyond every limit here, so it is refused.
     */
    private static function octal(string $field, string $what): int
    {
        $digits = trim(rtrim($field, "\0"), " \0");

        if ($digits === '') {
            return 0;
        }

        if (preg_match('/^[0-7]{1,11}$/D', $digits) !== 1) {
            throw new \UnexpectedValueException("The archive has a header with a malformed {$what}.");
        }

        return (int) octdec($digits);
    }

    /** The name a ustar header carries: its prefix, when it is ustar and has one, then its name. */
    private static function headerName(string $header): string
    {
        $name = self::field(substr($header, 0, 100));
        $prefix = substr($header, 257, 6) === "ustar\0" ? self::field(substr($header, 345, 155)) : '';

        return $prefix === '' ? $name : "{$prefix}/{$name}";
    }

    /** A fixed-width field up to its first NUL. */
    private static function field(string $field): string
    {
        $end = strpos($field, "\0");

        return $end === false ? $field : substr($field, 0, $end);
    }

    /**
     * The path and size a pax extended header sets for the next entry:
     * `<length> <key>=<value>\n` records; other keys are read past.
     *
     * @return array{?string, ?int}
     */
    private static function pax(string $data): array
    {
        $path = null;
        $size = null;
        $offset = 0;

        while ($offset < strlen($data)) {
            $space = strpos($data, ' ', $offset);
            $length = $space === false ? '' : substr($data, $offset, $space - $offset);

            if ($space === false || preg_match('/^[1-9]\d{0,5}$/D', $length) !== 1 || $offset + (int) $length > strlen($data)) {
                throw new \UnexpectedValueException('The archive has a malformed pax header.');
            }

            $record = substr($data, $space + 1, $offset + (int) $length - $space - 1);
            $offset += (int) $length;
            $equals = strpos($record, '=');

            if ($equals === false || !str_ends_with($record, "\n")) {
                throw new \UnexpectedValueException('The archive has a malformed pax header.');
            }

            $key = substr($record, 0, $equals);
            $value = substr($record, $equals + 1, -1);

            if ($key === 'path') {
                $path = $value;
            } elseif ($key === 'size') {
                if (preg_match('/^\d{1,12}$/D', $value) !== 1) {
                    throw new \UnexpectedValueException('The archive has a malformed pax size.');
                }

                $size = (int) $value;
            }
        }

        return [$path, $size];
    }

    /**
     * The entry's path, checked: relative, `/`-separated, no NUL, no
     * backslash, no empty, `.` or `..` segment. A directory may end in one
     * `/`, which is dropped.
     */
    private static function path(string $name, bool $directory): string
    {
        $path = $directory && str_ends_with($name, '/') ? substr($name, 0, -1) : $name;

        if (str_contains($path, "\0")) {
            throw new \UnexpectedValueException('The archive has an entry whose path holds a NUL byte.');
        }

        if (str_starts_with($path, '/')) {
            throw new \UnexpectedValueException('The archive entry ' . self::printable($path) . ' is an absolute path.');
        }

        if (str_contains($path, '\\')) {
            throw new \UnexpectedValueException('The archive entry ' . self::printable($path) . ' holds a backslash.');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                throw new \UnexpectedValueException('The archive entry ' . self::printable($path) . ' climbs out with "..".');
            }

            if ($segment === '' || $segment === '.') {
                throw new \UnexpectedValueException('The archive entry ' . self::printable($path) . ' has an empty or "." segment.');
            }
        }

        return $path;
    }

    private static function typeName(string $type): string
    {
        return match ($type) {
            '1' => 'a hard link',
            '2' => 'a symbolic link',
            '3' => 'a character device',
            '4' => 'a block device',
            '6' => 'a FIFO',
            default => 'of type ' . self::printable($type),
        };
    }

    /** A path or type as an error message may show it: JSON-quoted, so a control byte cannot reach the terminal. */
    private static function printable(string $value): string
    {
        return json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) ?: '"?"';
    }

    /** An entry's data, read whole, with its padding. */
    private static function data(GunzipStream $in, int $size): string
    {
        $data = $in->read($size);

        if (strlen($data) < $size) {
            throw new \UnexpectedValueException('The archive ends inside an entry.');
        }

        self::padding($in, $size);

        return $data;
    }

    /** Reads past an entry's data and its padding. */
    private static function skip(GunzipStream $in, int $size): void
    {
        for ($left = $size; $left > 0; $left -= $chunk) {
            $chunk = min($left, 65536);

            if (strlen($in->read($chunk)) < $chunk) {
                throw new \UnexpectedValueException('The archive ends inside an entry.');
            }
        }

        self::padding($in, $size);
    }

    private static function padding(GunzipStream $in, int $size): void
    {
        $padding = (self::BLOCK - $size % self::BLOCK) % self::BLOCK;

        if (strlen($in->read($padding)) < $padding) {
            throw new \UnexpectedValueException('The archive ends inside an entry.');
        }
    }

    /** Creates $dir and its parents, 0755, unless it is a directory already. */
    private static function directory(string $dir): void
    {
        if (is_dir($dir) && !is_link($dir)) {
            return;
        }

        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create {$dir}.");
        }

        chmod($dir, 0755);
    }

    /** Streams an entry's data into a new file, 0644. */
    private static function file(GunzipStream $in, string $path, int $size): void
    {
        $out = @fopen($path, 'x');

        if ($out === false) {
            throw new \RuntimeException("Could not create {$path}.");
        }

        try {
            for ($left = $size; $left > 0; $left -= $chunk) {
                $chunk = min($left, 65536);
                $bytes = $in->read($chunk);

                if (strlen($bytes) < $chunk) {
                    throw new \UnexpectedValueException('The archive ends inside an entry.');
                }

                if (fwrite($out, $bytes) !== $chunk) {
                    throw new \RuntimeException("Could not write {$path}.");
                }
            }
        } finally {
            fclose($out);
        }

        chmod($path, 0644);
        self::padding($in, $size);
    }
}
