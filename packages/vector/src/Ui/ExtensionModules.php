<?php

declare(strict_types=1);

namespace Kinetis\Vector\Ui;

/**
 * The UI extensions installed packages declare ({@see PackageExtensions}),
 * read once at boot as the hosted shell's index.html is: each module's
 * bytes are held in memory, so serving one is a lookup, never a file
 * read on the request path. Immutable and request-neutral.
 *
 * Each module is published at
 * `/vector/extensions/{vendor}/{package}/{hash}/{file}`, where `hash` is
 * a digest of the bytes this worker serves. A new release of the module
 * is a new URL, so the response can be cached as immutable and an
 * upgrade never serves a stale module — a request naming any other hash
 * is a 404, not the current bytes under an old name.
 */
final readonly class ExtensionModules
{
    public const string PATH = '/vector/extensions';

    /**
     * @param array<string, string> $modules each module's bytes, keyed by the
     *     path after {@see PATH}: `{vendor}/{package}/{hash}/{file}`
     */
    public function __construct(private array $modules = []) {}

    /**
     * A declared file that is no longer a readable file inside its package
     * when the worker boots is reported through error_log() and left out,
     * as a missing index.html leaves the shell answering 503: neither
     * stops the worker from booting.
     *
     * @param list<array{package: string, root: string, entry: string}> $declared
     */
    public static function load(array $declared, string $projectRoot): self
    {
        $modules = [];

        foreach ($declared as ['package' => $package, 'root' => $root, 'entry' => $entry]) {
            $root = $projectRoot . '/' . $root;
            $contents = PackageExtensions::contained($root, $entry) ? file_get_contents($root . '/' . $entry) : false;

            if ($contents === false) {
                error_log("Kinetis\\Vector\\Ui\\ExtensionModules: the UI extension \"{$entry}\" of package \"{$package}\" is not readable inside the package — not served.");

                continue;
            }

            $modules["{$package}/" . self::hash($contents) . '/' . basename($entry)] = $contents;
        }

        return new self($modules);
    }

    /** The first 64 bits of the SHA-256 of a module, as 16 hex digits. */
    public static function hash(string $contents): string
    {
        return substr(hash('sha256', $contents), 0, 16);
    }

    /** @return list<string> the same-origin URL of every module, in declared order */
    public function urls(): array
    {
        return array_map(static fn (string $key): string => self::PATH . '/' . $key, array_keys($this->modules));
    }

    /** The module published at `{vendor}/{package}/{hash}/{file}`, or null for any other path. */
    public function contents(string $path): ?string
    {
        return $this->modules[$path] ?? null;
    }
}
