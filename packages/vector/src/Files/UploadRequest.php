<?php

declare(strict_types=1);

namespace Kinetis\Vector\Files;

use Kinetis\Validation\Constraints\FileSize;
use Psr\Http\Message\UploadedFileInterface;

/** One file for a file or image field, as a multipart `file` part; the field's own maxBytes() applies on top. */
final readonly class UploadRequest
{
    public function __construct(
        #[FileSize(maxBytes: 50_000_000, minBytes: 1)]
        public UploadedFileInterface $file,
        /** The id of the record being edited, if any: without one, only create permission allows the upload. */
        public string $record = '',
    ) {}
}
