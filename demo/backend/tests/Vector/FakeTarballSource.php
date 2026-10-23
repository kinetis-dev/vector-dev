<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Vector\Ui\UiTarballSource;

/**
 * A registry that answers from fixtures and records what it was asked:
 * resolve() answers $tarball and $integrity, download() copies $archive.
 */
final class FakeTarballSource implements UiTarballSource
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(
        public string $tarball = 'https://registry.example/@kinetis/vector-ui/-/vector-ui.tgz',
        public ?string $integrity = null,
        public ?string $archive = null,
    ) {}

    #[\Override]
    public function resolve(string $package, string $version): array
    {
        $this->calls[] = "resolve {$package}@{$version}";

        return ['tarball' => $this->tarball, 'integrity' => $this->integrity];
    }

    #[\Override]
    public function download(string $url, string $path, int $maxBytes): void
    {
        $this->calls[] = "download {$url}";

        if ($this->archive === null || !copy($this->archive, $path)) {
            throw new \RuntimeException("Could not download {$url}.");
        }
    }
}
