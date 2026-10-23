<?php

declare(strict_types=1);

namespace Kinetis\Vector\Ui;

/**
 * Swaps a staged hosted bundle into the served directory while workers
 * keep serving from it.
 *
 * The stage is verified whole first — HostedBundle::verify(), and exactly
 * the files its manifest lists — so a refusal writes nothing into the
 * target. Then every file is copied beside its destination and renamed
 * over it, one atomic rename each: the assets first, then index.html,
 * then vector-bundle.json, so a shell never names an asset that is not
 * there yet and the manifest never describes files that are not.
 *
 * Last, what the outgoing manifest listed and the incoming one does not
 * is pruned. No write or deletion goes through a directory that is a link
 * or resolves outside the target: checked for every path before the first
 * write, and again before each rename, unlink and rmdir. A file no manifest recorded is never touched. A worker reads
 * index.html once, at boot, so workers are restarted after an install.
 */
final readonly class UiInstaller
{
    /**
     * @param ?\Closure(string): void $placed told each relative path as its
     *     rename lands, in order; for tests
     */
    public function __construct(private ?\Closure $placed = null) {}

    /**
     * Installs the bundle staged in $stage into $target.
     *
     * @param ?string $expectedVersion as HostedBundle::verify() takes it
     *
     * @throws \UnexpectedValueException when the stage is refused, or a directory of the
     *     target is a link or leads outside it; nothing was written
     * @throws \RuntimeException when the target cannot be written
     */
    public function install(string $stage, string $target, ?string $expectedVersion): HostedBundle
    {
        $bundle = HostedBundle::verify($stage, $expectedVersion);
        $extra = array_values(array_diff(self::files($stage), [...array_keys($bundle->files), HostedBundle::MANIFEST]))[0] ?? null;

        if ($extra !== null) {
            throw new \UnexpectedValueException("The admin UI bundle holds {$extra}, which " . HostedBundle::MANIFEST . ' does not list.');
        }

        self::directory($target);
        $root = realpath($target);

        if ($root === false) {
            throw new \RuntimeException("Could not resolve {$target}.");
        }

        $previous = HostedBundle::read("{$root}/" . HostedBundle::MANIFEST);
        $stale = $previous === null ? [] : array_diff_key($previous->files, $bundle->files);

        // Every directory a write or a deletion goes through stays inside the
        // target: checked whole before anything is written, then again at each step.
        foreach ([...array_keys($bundle->files), HostedBundle::MANIFEST, ...array_keys($stale)] as $path) {
            self::confine($root, $path);
        }

        // Verified: from here on the target changes.
        foreach (array_keys($bundle->files) as $path) {
            if ($path !== 'index.html') {
                $this->place("{$stage}/{$path}", $root, $path);
            }
        }

        $this->place("{$stage}/index.html", $root, 'index.html');
        $this->place("{$stage}/" . HostedBundle::MANIFEST, $root, HostedBundle::MANIFEST);
        self::prune($root, $stale);

        return $bundle;
    }

    /**
     * Whether a bundle of $version satisfies $expected: the same string,
     * or a dev build (`dev-<sha>`) when kinetis/vector is itself a dev
     * install. No semver: versions compare as plain strings.
     */
    public static function accepts(string $version, string $expected, bool $devInstall): bool
    {
        return $version === $expected || $devInstall && str_starts_with($version, 'dev-');
    }

    /**
     * The version HostedBundle::verify() is to hold the bundle in $dir to:
     * $expected, or none for a dev build accepted on a dev install.
     */
    public static function expectedFor(string $dir, string $expected, bool $devInstall): ?string
    {
        $version = HostedBundle::read($dir . '/' . HostedBundle::MANIFEST)?->version;

        return $version !== null && $version !== $expected && self::accepts($version, $expected, $devInstall) ? null : $expected;
    }

    /**
     * A new directory under the system's temporary one, 0700.
     *
     * @throws \RuntimeException when it cannot be made
     */
    public static function tempDir(string $purpose): string
    {
        $dir = sys_get_temp_dir() . "/vector-ui-{$purpose}-" . bin2hex(random_bytes(8));

        if (!@mkdir($dir, 0700) || !chmod($dir, 0700)) {
            throw new \RuntimeException("Could not create a private temporary directory under " . sys_get_temp_dir() . '.');
        }

        return $dir;
    }

    /** Removes $path and everything under it, following no link. */
    public static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove("{$path}/{$entry}");
                }
            }

            @rmdir($path);

            return;
        }

        if (is_link($path) || file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Every file under $dir, relative and `/`-separated.
     *
     * @return list<string>
     *
     * @throws \UnexpectedValueException at a link or anything else that is neither file nor directory
     */
    public static function files(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $item) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr((string) $item->getPathname(), strlen($dir) + 1));

            if ($item->isLink() || !$item->isFile() && !$item->isDir()) {
                throw new \UnexpectedValueException("The admin UI bundle holds {$relative}, which is neither a file nor a directory.");
            }

            if ($item->isFile()) {
                $files[] = $relative;
            }
        }

        return $files;
    }

    /**
     * Refuses $path when a directory on its way under $root, one that
     * exists already, is a link or resolves outside $root: a write or a
     * deletion through it would land elsewhere. What does not exist yet is
     * created under the checked part, so it stays inside.
     *
     * @param string $root the target, resolved
     *
     * @throws \UnexpectedValueException naming the directory that escapes
     */
    private static function confine(string $root, string $path): void
    {
        $dir = $root;

        foreach (array_slice(explode('/', $path), 0, -1) as $segment) {
            $dir .= "/{$segment}";

            if (!is_link($dir) && !file_exists($dir)) {
                return;
            }

            $real = realpath($dir);

            if (is_link($dir) || !is_dir($dir) || $real === false || $real !== $root && !str_starts_with($real, "{$root}/")) {
                throw new \UnexpectedValueException("Refusing to install the admin UI into {$root}: {$dir} is a link or leads outside it.");
            }
        }
    }

    /**
     * Copies $source beside $root/$path, then renames it over.
     *
     * @param string $root the target, resolved
     */
    private function place(string $source, string $root, string $path): void
    {
        $destination = "{$root}/{$path}";
        self::confine($root, $path);
        self::directory(dirname($destination));
        self::confine($root, $path);
        $temporary = dirname($destination) . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (!copy($source, $temporary) || !chmod($temporary, 0644)) {
            @unlink($temporary);

            throw new \RuntimeException("Could not write {$destination}.");
        }

        self::confine($root, $path);

        if (!rename($temporary, $destination)) {
            @unlink($temporary);

            throw new \RuntimeException("Could not write {$destination}.");
        }

        if ($this->placed !== null) {
            ($this->placed)($path);
        }
    }

    /**
     * Deletes the listed files under $root, and each directory that
     * leaves empty.
     *
     * @param string $root the target, resolved
     * @param array<string, string> $files relative path => sha256
     */
    private static function prune(string $root, array $files): void
    {
        foreach (array_keys($files) as $path) {
            $file = "{$root}/{$path}";
            self::confine($root, $path);

            if (is_file($file) && !is_link($file)) {
                @unlink($file);
            }

            for ($dir = dirname($path); $dir !== '.' && $dir !== ''; $dir = dirname($dir)) {
                // the directory itself, and every one above it, stays inside
                self::confine($root, "{$dir}/.");

                if (is_link("{$root}/{$dir}") || !@rmdir("{$root}/{$dir}")) {
                    break;
                }
            }
        }
    }

    private static function directory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create {$dir}.");
        }
    }
}
