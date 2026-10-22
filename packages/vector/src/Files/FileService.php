<?php

declare(strict_types=1);

namespace Kinetis\Vector\Files;

use Kinetis\Authorization\AuthorizationResponse;
use Kinetis\Authorization\Gate;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\FileResponse;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Exception\ForbiddenOperationException;
use Kinetis\Vector\Exception\RecordNotFoundException;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\ResourceDescriptor;
use League\Flysystem\Config as FlysystemConfig;
use League\Flysystem\Visibility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Uploads and downloads for file and image fields. An upload needs write
 * permission on a record the field can end up on, and its format is
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
        private \Kinetis\Vector\RichText\Document $document,
    ) {}

    /**
     * $recordId names the record being edited, when there is one: an
     * update is decided per record, so only with it can update grant the
     * upload.
     *
     * @return array{key: string, name: string|null, size: int, type: string}
     */
    public function upload(ResourceDescriptor $resource, string $fieldName, UploadedFileInterface $file, ?string $recordId = null): array
    {
        $field = $this->uploadField($resource, $fieldName);
        $this->authorizeUpload($resource, $fieldName, $recordId);

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

    /**
     * The record's file for $fieldName — or, with $key, an image one of the
     * record's rich-text documents shows from that field's uploads.
     */
    public function download(ResourceDescriptor $resource, string $id, string $fieldName, ?string $key = null): ResponseInterface
    {
        $record = $this->reader->show($resource, $id); // exists and is viewable
        $this->uploadField($resource, $fieldName, forWrite: false);
        $filesystem = $this->storage->filesystem();

        if ($key === null) {
            $key = $record[$fieldName] ?? null;
        } elseif (!$this->referenced($resource, $record, $fieldName, $key)) {
            throw RecordNotFoundException::of("{$resource->slug()}.{$fieldName}", $id);
        }

        if (!is_string($key) || !self::servable($resource, $fieldName, $key) || !$filesystem->fileExists($key)) {
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
     * Whether $key is an image a rich-text field of the record shows,
     * uploaded through $fieldName.
     *
     * @param array<string, mixed> $record
     */
    private function referenced(ResourceDescriptor $resource, array $record, string $fieldName, string $key): bool
    {
        if (!str_starts_with($key, "vector/{$resource->slug()}/{$fieldName}/")) {
            return false;
        }

        foreach ($resource->fields() as $name => $field) {
            if (($field['richtext']['imageField'] ?? null) === $fieldName && is_string($record[$name] ?? null)
                && in_array($key, $this->document->imageKeys($record[$name]), true)) {
                return true;
            }
        }

        return false;
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

    /**
     * Whether a stored value may be served for $fieldName: a relative
     * path with no empty, `.` or `..` segment, no backslash and no
     * control character, so the storage cannot resolve it elsewhere;
     * and, inside Vector's own `vector/` prefix, only under this
     * field's. A key written outside Vector — the application's own, or
     * one typed while uploads were unavailable — keeps being served.
     */
    private static function servable(ResourceDescriptor $resource, string $fieldName, string $key): bool
    {
        if ($key === '' || preg_match('~[\\\\\x00-\x1F\x7F]~', $key) === 1) {
            return false;
        }

        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        // compared without case first: a case-insensitive disk reads `Vector/` as `vector/`
        return strncasecmp($key, 'vector/', 7) !== 0 || str_starts_with($key, "vector/{$resource->slug()}/{$fieldName}/");
    }

    /**
     * An upload to $fieldName is allowed only to someone who may write a
     * record it can end up on: through a writer of the field that is on
     * the create form while the user may create, or on the update form
     * while the user may update $recordId. Refused, it is a 403.
     */
    private function authorizeUpload(ResourceDescriptor $resource, string $fieldName, ?string $recordId): void
    {
        $views = array_merge(...array_map(static fn (array $writer): array => $writer['views'], self::writers($resource, $fieldName)));

        if (in_array('create', $views, true) && $this->reader->permissions($resource)['create']) {
            return;
        }

        if ($recordId !== null && in_array('update', $views, true)) {
            $record = $this->reader->show($resource, $recordId); // exists and is viewable
            $policy = $this->reader->policy($resource);

            if (($record['_trashed'] ?? false) !== true
                && $this->gate->allows($this->user, static fn (CurrentUserInterface $u): bool|AuthorizationResponse => $policy->update($u, $record))) {
                return;
            }
        }

        throw new ForbiddenOperationException($recordId === null
            ? 'This account may not create records here.'
            : 'This account may not create records here or update this record.');
    }

    /**
     * The editable fields whose value an upload to $fieldName becomes: the
     * field itself, and every rich-text field that uploads its images
     * through it — the image field need not be on a form of its own.
     *
     * @return list<array<string, mixed>>
     */
    private static function writers(ResourceDescriptor $resource, string $fieldName): array
    {
        $writers = [];

        foreach ($resource->fields() as $name => $field) {
            if (($name === $fieldName || ($field['type'] === 'richtext' && ($field['richtext']['imageField'] ?? null) === $fieldName))
                && $field['readonly'] !== true && array_intersect(['create', 'update'], $field['views']) !== []) {
                $writers[] = $field;
            }
        }

        return $writers;
    }

    /** @return array<string, mixed> */
    private function uploadField(ResourceDescriptor $resource, string $fieldName, bool $forWrite = true): array
    {
        $field = $resource->field($fieldName);

        if ($field === null || !in_array($field['type'], ['file', 'image'], true)) {
            throw ValidationException::fromViolations([new Violation(['field'], 'unexpected_field', "is not a file or image field of {$resource->slug()}.")]);
        }

        if ($forWrite && self::writers($resource, $fieldName) === []) {
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
