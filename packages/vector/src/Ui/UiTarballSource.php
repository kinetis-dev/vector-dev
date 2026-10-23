<?php

declare(strict_types=1);

namespace Kinetis\Vector\Ui;

/**
 * Where `vector:install-ui` finds a published @kinetis/vector-ui: the
 * registry's answer for one version, and the tarball it names.
 * HttpsTarballSource is the one implementation; the command checks what
 * a source returns — https URLs, a sha512 integrity — rather than
 * trusting it.
 */
interface UiTarballSource
{
    /**
     * The tarball URL and the `dist.integrity` (an SRI string, null when
     * the registry gives none) the registry publishes for $package at
     * $version.
     *
     * @return array{tarball: string, integrity: ?string}
     *
     * @throws \RuntimeException when the registry cannot be read, or has no such version
     */
    public function resolve(string $package, string $version): array;

    /**
     * Downloads $url into the new file $path, refusing more than $maxBytes.
     *
     * @throws \RuntimeException when it cannot be downloaded whole
     */
    public function download(string $url, string $path, int $maxBytes): void;
}
