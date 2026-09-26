<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

/** An uploaded document: offered for download, never rendered inline. */
final class File extends UploadField
{
    public function type(): string
    {
        return 'file';
    }

    protected function defaultFormats(): array
    {
        return ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'text/plain' => 'txt', 'text/csv' => 'csv'];
    }

    protected function defaultMaxBytes(): int
    {
        return 10_000_000;
    }
}
