<?php

declare(strict_types=1);

namespace Kinetis\Vector\Import;

use Kinetis\Validation\Constraints\FileExtension;
use Kinetis\Validation\Constraints\FileSize;
use Psr\Http\Message\UploadedFileInterface;

/** The multipart body of an import: the CSV and, for the import itself, the mapping as JSON. */
final readonly class ImportRequest
{
    public function __construct(
        #[FileSize(maxBytes: 8_000_000)]
        #[FileExtension(['csv', 'txt'])]
        public UploadedFileInterface $file,
        public string $mapping = '{}',
    ) {}
}
