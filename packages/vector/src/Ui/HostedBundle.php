<?php

declare(strict_types=1);

namespace Kinetis\Vector\Ui;

use Kinetis\Vector\Registry\VectorRegistry;

/**
 * The hosted admin bundle a build left in `resources/dist`, as its
 * manifest `vector-bundle.json` describes it: the package and version
 * that built it, the vector-dev commit it was built from, the descriptor
 * contract it speaks and the sha256 of every other file in the
 * directory. The build script writes the manifest; `vector:install`
 * verifies the directory against it before publishing anything, so a
 * stale or altered bundle is refused rather than served.
 */
final readonly class HostedBundle
{
    public const string MANIFEST = 'vector-bundle.json';

    public const string PACKAGE = 'kinetis/vector';

    private const string VERSION_PATTERN = '/^(?:(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?|dev-[0-9a-f]{12})$/';

    /**
     * @param array<string, string> $files relative path => sha256
     */
    private function __construct(
        public string $version,
        public string $source,
        public array $files,
    ) {}

    /**
     * Reads the manifest in $dist and checks the directory against it:
     * this package, this descriptor contract, the installed release when
     * there is one, and exactly the listed files with the listed hashes.
     *
     * @param ?string $release the installed kinetis/vector release, without
     *                         a leading "v"; null for a dev install
     *
     * @throws \UnexpectedValueException one line naming the cause and the remedy
     */
    public static function verify(string $dist, ?string $release): self
    {
        $remedy = $release === null
            ? 'Rebuild it with `pnpm generate:hosted`, or delete it.'
            : 'Reinstall kinetis/vector to restore the bundle the release shipped.';
        $refuse = static fn(string $cause): \UnexpectedValueException
            => new \UnexpectedValueException("Refusing to publish the admin bundle in {$dist}: {$cause}. {$remedy}");

        $data = self::manifest($dist, $refuse);
        $files = $data['files'];

        if ($data['package'] !== self::PACKAGE) {
            throw $refuse("it was built for {$data['package']}, not " . self::PACKAGE);
        }

        if ($data['descriptorVersion'] !== VectorRegistry::VERSION) {
            throw $refuse("it speaks descriptor version {$data['descriptorVersion']}; this kinetis/vector serves version " . VectorRegistry::VERSION);
        }

        if ($release !== null && $data['version'] !== $release) {
            throw $refuse("it was built for version {$data['version']}; kinetis/vector {$release} is installed");
        }

        if (!isset($files['index.html'])) {
            throw $refuse(self::MANIFEST . ' lists no index.html, so there is no shell to publish');
        }

        self::checkFiles($dist, $files, $refuse);

        return new self($data['version'], $data['source'], $files);
    }

    /**
     * The manifest in $dist, read and checked for shape: every member
     * present with its type and format, every listed path inside the
     * bundle and every hash a sha256.
     *
     * @param \Closure(string): \UnexpectedValueException $refuse
     * @return array{package: string, version: string, descriptorVersion: int, source: string, files: array<string, string>}
     *
     * @throws \UnexpectedValueException when it is missing or malformed
     */
    private static function manifest(string $dist, \Closure $refuse): array
    {
        $manifest = $dist . '/' . self::MANIFEST;

        if (!is_file($manifest)) {
            throw $refuse('it has no ' . self::MANIFEST . ', so nothing says which build it is');
        }

        $contents = file_get_contents($manifest);

        try {
            $data = $contents === false ? null : json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }

        if (
            !is_array($data)
            || !is_string($data['package'] ?? null)
            || !is_string($data['version'] ?? null) || preg_match(self::VERSION_PATTERN, $data['version']) !== 1
            || !is_int($data['descriptorVersion'] ?? null)
            || !is_string($data['source'] ?? null) || preg_match('/^[0-9a-f]{40}$/', $data['source']) !== 1
            || !is_array($data['files'] ?? null)
        ) {
            throw $refuse(self::MANIFEST . ' is malformed');
        }

        $files = [];

        foreach ($data['files'] as $path => $hash) {
            if (!is_string($path) || !self::isRelativePath($path) || !is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw $refuse(self::MANIFEST . ' is malformed');
            }

            $files[$path] = $hash;
        }

        return [
            'package' => $data['package'],
            'version' => $data['version'],
            'descriptorVersion' => $data['descriptorVersion'],
            'source' => $data['source'],
            'files' => $files,
        ];
    }

    /**
     * Checks that $dist holds exactly the listed files, besides the
     * manifest, with the listed hashes.
     *
     * @param array<string, string> $files relative path => sha256
     * @param \Closure(string): \UnexpectedValueException $refuse
     *
     * @throws \UnexpectedValueException naming the first file that differs
     */
    private static function checkFiles(string $dist, array $files, \Closure $refuse): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dist, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $item) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr((string) $item->getPathname(), strlen($dist) + 1));

            if ($relative !== self::MANIFEST && !isset($files[$relative])) {
                throw $refuse("{$relative} is not in " . self::MANIFEST);
            }
        }

        foreach ($files as $path => $hash) {
            if (!is_file("{$dist}/{$path}")) {
                throw $refuse("{$path} is listed in " . self::MANIFEST . ' but missing');
            }

            if (hash_file('sha256', "{$dist}/{$path}") !== $hash) {
                throw $refuse("{$path} changed after " . self::MANIFEST . ' was written');
            }
        }
    }

    /**
     * A `/`-separated path that stays inside the bundle: no empty, `.` or
     * `..` segment, no leading slash, no backslash.
     */
    private static function isRelativePath(string $path): bool
    {
        if ($path === '' || $path === self::MANIFEST || str_contains($path, '\\')) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
