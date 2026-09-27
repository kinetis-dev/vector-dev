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
        /** create (the default), update (only existing records) or upsert (either) */
        public string $mode = 'create',
        /** The field matching a row to a record in update and upsert: the key or a unique field, mapped. */
        public string $key = '',
    ) {}
}
