<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

/** An uploaded raster image, shown inline as a thumbnail and on the record. */
final class Image extends UploadField
{
    public function type(): string
    {
        return 'image';
    }

    protected function defaultFormats(): array
    {
        return ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    }

    protected function defaultMaxBytes(): int
    {
        return 5_000_000;
    }

    /** An image field accepts raster formats only: a vector format such as SVG can carry script. */
    public function accept(array $formats): static
    {
        foreach (array_keys($formats) as $type) {
            if (!in_array($type, ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/avif'], true)) {
                throw new \InvalidArgumentException("\"{$this->name}\": an image accepts png, jpeg, webp, gif or avif, not {$type}.");
            }
        }

        return parent::accept($formats);
    }
}
