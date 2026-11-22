<?php

declare(strict_types=1);

namespace Kinetis\Vector\Ui;

use Kinetis\Vector\Registry\VectorRegistry;

/**
 * The hosted admin bundle in a directory, as its manifest
 * `vector-bundle.json` describes it: the npm package and version that
 * built it, the vector-dev commit it was built from, the descriptor
 * contract it speaks and the sha256 of every file it ships. A bundle
 * built from Vector's layer plus another package's names, in the
 * optional `includes`, each npm package and version it was built with:
 * `{"@kinetis/vector-ui": "<x.y.z>"}`. The build script writes the
 * manifest into the package's `dist/static`, the npm package carries it,
 * and TarballInstaller installs it beside `index.html` in the served
 * directory, where it may be read publicly.
 */
final readonly class HostedBundle
{
    public const string MANIFEST = 'vector-bundle.json';

    /** The npm package a bundle comes from; not kinetis/vector, the Composer package. */
    public const string PACKAGE = UiPin::PACKAGE;

    private const string VERSION_PATTERN = '/^(?:(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?|dev-[0-9a-f]{12})$/D';

    /**
     * @param array<string, string> $files relative path => sha256
     * @param array<string, string> $includes npm package => version
     */
    private function __construct(
        public string $package,
        public string $version,
        public int $descriptorVersion,
        public string $source,
        public array $files,
        public array $includes = [],
    ) {}

    /**
     * The version of $package this bundle is, or includes; null when it
     * is neither.
     */
    public function carries(string $package): ?string
    {
        return $package === $this->package ? $this->version : $this->includes[$package] ?? null;
    }

    /**
     * Reads the manifest in $dir and checks the directory against it:
     * $package, this descriptor contract, $expectedVersion when one is
     * given, each of $includes at exactly its version, and every listed
     * file present with its listed hash. Files the manifest does not list
     * are not this check's concern: the served directory may hold the
     * deployment's own files beside it.
     *
     * @param ?string $expectedVersion the version the bundle must be,
     *                                 compared as a plain string; null
     *                                 accepts any
     * @param array<string, string> $includes npm package => the version
     *                                        the bundle must include
     * @param string $command the install command the remedy names
     *
     * @throws \UnexpectedValueException one line naming the cause and the remedy
     */
    public static function verify(string $dir, ?string $expectedVersion, string $package = self::PACKAGE, array $includes = [], string $command = 'vector:install-ui'): self
    {
        $refuse = static fn(string $cause): \UnexpectedValueException
            => new \UnexpectedValueException("The admin UI bundle in {$dir} does not verify: {$cause}. Run `vendor/bin/kinetis {$command}` to install a verified one.");

        $manifest = $dir . '/' . self::MANIFEST;

        if (!is_file($manifest)) {
            throw $refuse('it has no ' . self::MANIFEST . ', so nothing says which build it is');
        }

        $bundle = self::read($manifest) ?? throw $refuse(self::MANIFEST . ' is malformed');

        if ($bundle->package !== $package) {
            throw $refuse("it was built for {$bundle->package}, not {$package}");
        }

        if ($bundle->descriptorVersion !== VectorRegistry::VERSION) {
            throw $refuse("it speaks descriptor version {$bundle->descriptorVersion}; this kinetis/vector serves version " . VectorRegistry::VERSION);
        }

        if ($expectedVersion !== null && $bundle->version !== $expectedVersion) {
            throw $refuse("it is version {$bundle->version}; {$expectedVersion} is expected");
        }

        foreach ($includes as $included => $version) {
            $actual = $bundle->includes[$included] ?? null;

            if ($actual === null) {
                throw $refuse("it does not say which {$included} it was built with; {$version} is expected");
            }

            if ($actual !== $version) {
                throw $refuse("it was built with {$included} {$actual}; {$version} is expected");
            }
        }

        if (!isset($bundle->files['index.html'])) {
            throw $refuse(self::MANIFEST . ' lists no index.html, so there is no shell to serve');
        }

        foreach ($bundle->files as $path => $hash) {
            $file = "{$dir}/{$path}";

            if (!is_file($file) || is_link($file)) {
                throw $refuse("{$path} is listed in " . self::MANIFEST . ' but missing');
            }

            if (hash_file('sha256', $file) !== $hash) {
                throw $refuse("{$path} changed after " . self::MANIFEST . ' was written');
            }
        }

        return $bundle;
    }

    /**
     * The manifest in $file, checked for shape only — every member present
     * with its type and format, every listed path inside the bundle, every
     * hash a sha256 and, when `includes` is there, an object of package
     * names and versions — or null when it is missing or malformed. No
     * file is hashed.
     */
    public static function read(string $file): ?self
    {
        $contents = is_file($file) ? file_get_contents($file) : false;

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
            || !is_string($data['source'] ?? null) || preg_match('/^[0-9a-f]{40}$/D', $data['source']) !== 1
            || !is_array($data['files'] ?? null)
        ) {
            return null;
        }

        $files = [];

        foreach ($data['files'] as $path => $hash) {
            if (!is_string($path) || !self::isRelativePath($path) || !is_string($hash) || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) {
                return null;
            }

            $files[$path] = $hash;
        }

        $includes = [];
        $listed = $data['includes'] ?? [];

        if (!is_array($listed)) {
            return null;
        }

        foreach ($listed as $package => $version) {
            if (!is_string($package) || $package === '' || $package === $data['package'] || !is_string($version) || preg_match(self::VERSION_PATTERN, $version) !== 1) {
                return null;
            }

            $includes[$package] = $version;
        }

        return new self($data['package'], $data['version'], $data['descriptorVersion'], $data['source'], $files, $includes);
    }

    /**
     * A `/`-separated path that stays inside the bundle: no empty, `.` or
     * `..` segment, no leading slash, no backslash, no NUL, and not the
     * manifest's own name.
     */
    public static function isRelativePath(string $path): bool
    {
        if ($path === '' || $path === self::MANIFEST || str_contains($path, '\\') || str_contains($path, "\0")) {
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
