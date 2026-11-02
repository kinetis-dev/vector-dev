<?php

declare(strict_types=1);

namespace Kinetis\Vector\Translation;

use Kinetis\Vector\Ui\PackageExtensions;

/**
 * The translations directories installed Composer packages declare for
 * the labels they emit on the server, read from Composer's own record of
 * what is installed. A package names one directory, relative to its own
 * root, in its composer.json:
 *
 *     "extra": {
 *         "kinetis": {
 *             "vector": { "translations": "resources/translations" }
 *         }
 *     }
 *
 * The directory holds `{locale}.php` files in {@see MessagesTranslator}'s
 * format. Declarative on purpose, like {@see PackageExtensions}: nothing
 * a package's bootstrap does at run time takes part. The declarations are
 * compiled into Vector's section of the AOT cache ({@see \Kinetis\Vector\Registry\VectorRegistry}),
 * sorted by package name, and Vector's bootstrap layers them between its
 * own `translations/` and the application's VECTOR_TRANSLATIONS_DIR, so
 * the application always has the last word.
 *
 * The value must be a relative path to a directory inside the package:
 * no `.`/`..` or hidden segment and no symlink out of the package. A
 * declaration that breaks one of these is the package author's mistake
 * and is reported through error_log() and skipped, never fatal for the
 * consuming application — at compile time, and again at boot for a
 * directory that has gone since.
 */
final readonly class PackageTranslations
{
    /** relative path segments that never start with a dot */
    private const string DIRECTORY = '~^[A-Za-z0-9_-][A-Za-z0-9._-]*(?:/[A-Za-z0-9_-][A-Za-z0-9._-]*)*$~D';

    /**
     * @return list<array{package: string, root: string, directory: string}> `root`
     *     is the package's install directory relative to $projectRoot, `directory`
     *     the declared one relative to that root
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

            if (!is_string($name) || !is_string($installPath) || !is_array($vector) || !array_key_exists('translations', $vector)) {
                continue;
            }

            $root = 'vendor/composer/' . $installPath;
            $directory = $vector['translations'];

            if (!is_string($directory) || preg_match(self::DIRECTORY, $directory) !== 1) {
                $shown = json_encode($directory, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                self::skip($name, 'extra.kinetis.vector.translations ' . (is_string($shown) ? $shown : get_debug_type($directory)) . ' is not a relative path to a directory inside the package');

                continue;
            }

            if (!self::contained($projectRoot . '/' . $root, $directory)) {
                self::skip($name, "\"{$directory}\" is not a directory inside the package");

                continue;
            }

            $declared[$name] = ['package' => $name, 'root' => $root, 'directory' => $directory];
        }

        ksort($declared, SORT_STRING);

        return array_values($declared);
    }

    /**
     * The absolute directories to read at boot, in the order given: a
     * declared directory that is no longer one inside its package — removed
     * or replaced since the build — is reported and left out, so the
     * translator never meets a directory that does not exist.
     *
     * @param list<array{package: string, root: string, directory: string}> $declared
     * @return list<string>
     */
    public static function directories(array $declared, string $projectRoot): array
    {
        $directories = [];

        foreach ($declared as ['package' => $package, 'root' => $root, 'directory' => $directory]) {
            if (!self::contained($projectRoot . '/' . $root, $directory)) {
                self::skip($package, "\"{$directory}\" is no longer a directory inside the package");

                continue;
            }

            $directories[] = $projectRoot . '/' . $root . '/' . $directory;
        }

        return $directories;
    }

    /** Whether $directory resolves, through any symlink, to a directory inside $root. */
    private static function contained(string $root, string $directory): bool
    {
        $resolved = PackageExtensions::inside($root, $directory);

        return $resolved !== null && is_dir($resolved);
    }

    private static function skip(string $package, string $reason): void
    {
        error_log("Kinetis\\Vector\\Translation\\PackageTranslations: package \"{$package}\" declares translations Vector cannot read: {$reason} — skipped.");
    }
}
