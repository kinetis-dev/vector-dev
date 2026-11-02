<?php

declare(strict_types=1);

namespace Kinetis\Vector\Ui;

/**
 * The runtime UI extensions installed Composer packages declare, read
 * from Composer's own record of what is installed. A package lists its
 * ES modules, relative to its own root, in its composer.json:
 *
 *     "extra": {
 *         "kinetis": {
 *             "vector": { "extensions": ["resources/ui/queues.js"] }
 *         }
 *     }
 *
 * Declarative on purpose: nothing a package's bootstrap does at run
 * time takes part, so the result is the same whichever order Composer
 * records — and the framework boots — the packages in. It is compiled
 * into Vector's section of the AOT cache ({@see \Kinetis\Vector\Registry\VectorRegistry}),
 * which the framework binds before any package bootstrap runs; the
 * entries are sorted by package name, then kept in declared order.
 *
 * An entry must name a `.js` or `.mjs` file under the package's own
 * directory: relative, no `.`/`..` or hidden segment, no symlink out of
 * the package, and a file name unique within the package (it is the
 * last segment of the URL). A declaration that breaks one of these is
 * the package author's mistake and, as the framework treats its own
 * `extra.kinetis` keys, is reported through error_log() and skipped,
 * never fatal for the consuming application.
 */
final readonly class PackageExtensions
{
    /** relative path segments that never start with a dot, ending in a .js or .mjs file */
    private const string ENTRY = '~^(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*[A-Za-z0-9_-][A-Za-z0-9._-]*\.m?js$~D';

    /** a Composer package name, vendor/name, which the URL carries verbatim */
    private const string PACKAGE = '~^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$~D';

    /**
     * @return list<array{package: string, root: string, entry: string}> `root` is
     *     the package's install directory relative to $projectRoot, `entry` the
     *     declared file relative to that root
     */
    public static function discover(string $projectRoot): array
    {
        $installed = $projectRoot . '/vendor/composer/installed.json';

        if (!is_file($installed)) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($installed), associative: true);
        $packages = is_array($decoded) ? ($decoded['packages'] ?? null) : null;

        if (!is_array($packages)) {
            return [];
        }

        $declared = [];

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $name = $package['name'] ?? null;
            $installPath = $package['install-path'] ?? null;
            $vector = is_array($package['extra'] ?? null) && is_array($package['extra']['kinetis'] ?? null) ? ($package['extra']['kinetis']['vector'] ?? null) : null;

            if (!is_string($name) || !is_string($installPath) || !is_array($vector) || !array_key_exists('extensions', $vector)) {
                continue;
            }

            $declared[$name] = self::entries($projectRoot, $name, 'vendor/composer/' . $installPath, $vector['extensions']);
        }

        ksort($declared, SORT_STRING);

        return array_merge(...array_values($declared));
    }

    /**
     * Whether $entry resolves, through any symlink, to a regular file
     * inside $root — re-checked at boot, where the file is read.
     */
    public static function contained(string $root, string $entry): bool
    {
        $file = self::inside($root, $entry);

        return $file !== null && is_file($file);
    }

    /**
     * The real path $path resolves to, through any symlink, when that is
     * strictly inside $root's own real path; null when it is missing or
     * escapes. The caller checks what kind of entry it must be.
     */
    public static function inside(string $root, string $path): ?string
    {
        $base = realpath($root);
        $resolved = realpath($root . '/' . $path);

        return $base !== false && $resolved !== false && str_starts_with($resolved, rtrim($base, '/') . '/') ? $resolved : null;
    }

    /**
     * @return list<array{package: string, root: string, entry: string}>
     */
    private static function entries(string $projectRoot, string $name, string $root, mixed $extensions): array
    {
        if (preg_match(self::PACKAGE, $name) !== 1) {
            self::skip($name, 'its name cannot be part of an extension URL');

            return [];
        }

        if (!is_array($extensions) || !array_is_list($extensions)) {
            self::skip($name, 'extra.kinetis.vector.extensions is not a list of file paths');

            return [];
        }

        $entries = [];
        $files = [];

        foreach ($extensions as $entry) {
            if (!is_string($entry) || preg_match(self::ENTRY, $entry) !== 1) {
                $shown = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                self::skip($name, 'the entry ' . (is_string($shown) ? $shown : get_debug_type($entry)) . ' is not a relative path to a .js or .mjs file inside the package');

                continue;
            }

            if (!self::contained($projectRoot . '/' . $root, $entry)) {
                self::skip($name, "the entry \"{$entry}\" is not a file inside the package");

                continue;
            }

            $file = basename($entry);

            if (isset($files[$file])) {
                self::skip($name, "the entry \"{$entry}\" has the same file name as \"{$files[$file]}\"");

                continue;
            }

            $files[$file] = $entry;
            $entries[] = ['package' => $name, 'root' => $root, 'entry' => $entry];
        }

        return $entries;
    }

    private static function skip(string $package, string $reason): void
    {
        error_log("Kinetis\\Vector\\Ui\\PackageExtensions: package \"{$package}\" declares a UI extension Vector cannot serve: {$reason} — skipped.");
    }
}
