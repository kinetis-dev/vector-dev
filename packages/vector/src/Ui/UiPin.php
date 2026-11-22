<?php

declare(strict_types=1);

namespace Kinetis\Vector\Ui;

/**
 * The @kinetis/vector-ui release this kinetis/vector expects, as
 * `resources/ui.json` pins it: `{"package":"@kinetis/vector-ui",
 * "version":"<x.y.z>"}` and nothing else. `vector:install-ui` installs
 * that version by default, and the dashboard warns when the UI it runs
 * is another. The pin names the npm package, not the Composer one. A
 * package that ships its own hosted bundle pins it the same way, read
 * with its package name as the one expected.
 */
final readonly class UiPin
{
    public const string PACKAGE = '@kinetis/vector-ui';

    private const string EXACT_VERSION = '/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/D';

    private function __construct(
        public string $package,
        public string $version,
    ) {}

    /** The pin this package ships. */
    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/resources/ui.json';
    }

    /**
     * @param string $package the npm package the pin must name
     *
     * @throws \UnexpectedValueException naming why the pin is unusable
     */
    public static function fromFile(string $path, string $package = self::PACKAGE): self
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new \UnexpectedValueException("The UI pin {$path} is missing.");
        }

        try {
            $data = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }

        if (
            !is_array($data)
            || array_keys($data) !== ['package', 'version']
            || $data['package'] !== $package
            || !is_string($data['version'])
        ) {
            throw new \UnexpectedValueException("The UI pin {$path} is malformed: it must be exactly {\"package\":\"{$package}\",\"version\":\"<x.y.z>\"}.");
        }

        if (preg_match(self::EXACT_VERSION, $data['version']) !== 1) {
            throw new \UnexpectedValueException("The UI pin {$path} names \"{$data['version']}\", not an exact x.y.z version.");
        }

        return new self($data['package'], $data['version']);
    }

    /** The pin, or null when it is missing or malformed: no expectation. */
    public static function tryFromFile(string $path, string $package = self::PACKAGE): ?self
    {
        try {
            return self::fromFile($path, $package);
        } catch (\UnexpectedValueException) {
            return null;
        }
    }
}
