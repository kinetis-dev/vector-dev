<?php

declare(strict_types=1);

namespace Kinetis\Vector\Files;

use Kinetis\Authorization\AuthorizationResponse;
use Kinetis\Authorization\Gate;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\FileResponse;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Exception\RecordNotFoundException;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\ResourceDescriptor;
use League\Flysystem\Config as FlysystemConfig;
use League\Flysystem\Visibility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Uploads and downloads for file and image fields. An upload's format is
 * detected from its bytes and must be one the field accepts; the stored
 * key is generated here, private, under `vector/{resource}/{field}/`, and
 * the client's filename is returned as data, never used as a path. A
 * download serves a stored file to whoever may view the record and the
 * field: images inline, everything else as an attachment, never sniffed.
 */
final readonly class FileService
{
    /** Raster formats a browser may render inline. */
    private const array INLINE = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/avif'];

    public function __construct(
        private VectorStorage $storage,
        private ResourceService $reader,
        private Gate $gate,
        private CurrentUserInterface $user,
    ) {}

    /** @return array{key: string, name: string|null, size: int, type: string} */
    public function upload(ResourceDescriptor $resource, string $fieldName, UploadedFileInterface $file): array
    {
        $policy = $this->reader->policy($resource);
        $this->gate->authorize($this->user, static fn (CurrentUserInterface $u): bool|AuthorizationResponse => $policy->viewAny($u));
        $field = $this->uploadField($resource, $fieldName);

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw self::refused('did not arrive whole; upload it again.');
        }

        $contents = (string) $file->getStream();
        /** @var array{accept: array<string, string>, maxBytes: int} $props */
        $props = $field['props'];

        if (strlen($contents) > $props['maxBytes']) {
            throw self::refused('is larger than ' . self::megabytes($props['maxBytes']) . '.');
        }

        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $type = is_string($type) ? $type : 'application/octet-stream';
        $extension = $props['accept'][$type] ?? ($type === 'text/plain' ? ($props['accept']['text/csv'] ?? null) : null);

        if ($extension === null) {
            throw self::refused('must be ' . implode(', ', array_values(array_unique($props['accept']))) . '.');
        }

        $key = sprintf('vector/%s/%s/%s.%s', $resource->slug(), $fieldName, bin2hex(random_bytes(16)), $extension);
        $this->storage->filesystem()->write($key, $contents, [FlysystemConfig::OPTION_VISIBILITY => Visibility::PRIVATE]);

        return ['key' => $key, 'name' => $file->getClientFilename(), 'size' => strlen($contents), 'type' => $type];
    }

    public function download(ResourceDescriptor $resource, string $id, string $fieldName): ResponseInterface
    {
        $record = $this->reader->show($resource, $id); // exists and is viewable
        $this->uploadField($resource, $fieldName, forWrite: false);
        $key = $record[$fieldName] ?? null;
        $filesystem = $this->storage->filesystem();

        if (!is_string($key) || $key === '' || !$filesystem->fileExists($key)) {
            throw RecordNotFoundException::of("{$resource->slug()}.{$fieldName}", $id);
        }

        $contents = $filesystem->read($key);
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $type = is_string($type) ? $type : 'application/octet-stream';
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'no-store',
        ];

        if (in_array($type, self::INLINE, true)) {
            return FileResponse::fromContents($contents, $type, 200, null, [...$headers, 'Content-Disposition' => 'inline']);
        }

        return FileResponse::fromContents($contents, $type, 200, basename($key), $headers);
    }

    /**
     * Whether $value is a key this service issued for $resource's $field
     * and the file is stored — what a written file value must be.
     */
    public function issued(ResourceDescriptor $resource, string $fieldName, string $value): bool
    {
        $prefix = preg_quote("vector/{$resource->slug()}/{$fieldName}/", '~');

        return preg_match("~^{$prefix}[0-9a-f]{32}\\.[a-z0-9]{1,10}$~D", $value) === 1
            && $this->storage->available()
            && $this->storage->filesystem()->fileExists($value);
    }

    /** @return array<string, mixed> */
    private function uploadField(ResourceDescriptor $resource, string $fieldName, bool $forWrite = true): array
    {
        $field = $resource->field($fieldName);

        if ($field === null || !in_array($field['type'], ['file', 'image'], true)) {
            throw ValidationException::fromViolations([new Violation(['field'], 'unexpected_field', "is not a file or image field of {$resource->slug()}.")]);
        }

        if ($forWrite && ($field['readonly'] === true || array_intersect(['create', 'update'], $field['views']) === [])) {
            throw ValidationException::fromViolations([new Violation(['field'], 'read_only', 'is not edited here.')]);
        }

        return $field;
    }

    private static function refused(string $message): ValidationException
    {
        return ValidationException::fromViolations([new Violation(['file'], 'invalid_file', $message)]);
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1_000_000, 1, '.', ''), '0'), '.') . ' MB';
    }
}
