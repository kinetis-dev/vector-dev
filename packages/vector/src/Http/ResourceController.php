<?php

declare(strict_types=1);

namespace Kinetis\Vector\Http;

use Kinetis\Http\Attributes\Delete;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Patch;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Http\Responses\FileResponse;
use Kinetis\Http\Responses\JsonResponse;
use Kinetis\Http\Responses\PlainTextResponse;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Http\Attributes\Body;
use Kinetis\Vector\Actions\ActionRunner;
use Kinetis\Vector\Exception\ForbiddenOperationException;
use Kinetis\Vector\Exception\RecordNotFoundException;
use Kinetis\Vector\Import\CsvReader;
use Kinetis\Vector\Import\ImportRequest;
use Kinetis\Vector\Import\RowMapper;
use Kinetis\Vector\Jobs\ExportJob;
use Kinetis\Vector\Jobs\ImportJob;
use Kinetis\Vector\Jobs\VectorQueue;
use Kinetis\Vector\Query\CsvWriter;
use Kinetis\Vector\Store\AuditStore;
use Kinetis\Vector\Store\JobStore;
use Kinetis\Vector\Widgets\WidgetRunner;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Query\ListRequest;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Write\RecordWriter;
use Kinetis\Vector\Write\ValueConverter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The generic record endpoints. `{resource}` resolves against the
 * compiled registry at request time. The query string and the JSON body
 * are read from the request itself: the resource decides their shape, so
 * no fixed DTO could.
 */
#[RoutePrefix('/vector/r')]
#[Middleware('@vector')]
final readonly class ResourceController
{
    public function __construct(
        private ResourceService $service,
        private RecordWriter $writer,
        private ValueConverter $converter,
        private ActionRunner $actions,
        private JobStore $jobs,
        private VectorQueue $queue,
        private CurrentUserInterface $user,
        private AuditStore $audit,
        private \Kinetis\Vector\Files\FileService $files,
        private WidgetRunner $widgets,
        private RowMapper $mapper,
        private \Kinetis\Vector\VectorDefaults $defaults,
        private \Kinetis\Vector\Translation\LocaleResolver $locales,
        private \Kinetis\Vector\Translation\Labels $labels,
        private \Kinetis\Vector\Auth\ActorContext $actor,
    ) {}

    /** @return array<string, mixed> */
    #[Get('/{resource}')]
    public function index(string $resource, ServerRequestInterface $request): array
    {
        $descriptor = $this->service->resource($resource);

        return $this->service->list($descriptor, ListRequest::fromQueryParams($request->getQueryParams(), $descriptor, $this->converter));
    }

    /**
     * The list under the same grammar as a CSV file, inline up to the
     * resource's export limit. `columns` picks the columns; without it
     * every index column is exported. `format=xlsx`, where offered, is
     * always a job: openspout writes through temporary files, which only
     * the queue worker may block on.
     */
    #[Get('/{resource}/export')]
    public function export(string $resource, ServerRequestInterface $request): ResponseInterface
    {
        $descriptor = $this->service->resource($resource);
        $params = $request->getQueryParams();

        $format = $params['format'] ?? 'csv';
        $formats = $this->queue->available() ? $this->defaults->exportFormats : ['csv'];

        if (!in_array($format, $formats, true)) {
            $hint = $format === 'xlsx' && in_array('xlsx', $this->defaults->exportFormats, true) ? ' An XLSX export runs in the queue, which is not configured.' : '';

            throw ValidationException::fromViolations([new Violation(['format'], 'invalid_choice', 'must be ' . implode(' or ', $formats) . '.' . $hint)]);
        }

        $this->service->authorizeExport($descriptor); // before any counting or queueing
        $listRequest = ListRequest::fromQueryParams($params, $descriptor, $this->converter)->unpaged();
        $fields = $listRequest->columns ?? $descriptor->toArray()['table']['columns'];
        $total = $this->service->exportCount($descriptor, $listRequest);

        // above the inline limit, or as XLSX, the export becomes a job
        if ($this->queue->available() && ($total > $descriptor->exportLimit() || $format === 'xlsx')) {
            /** @var array<string, mixed> $stored */
            $stored = array_intersect_key($params, array_flip(['filter', 'q', 'sort', 'columns']));
            $jobId = $this->jobs->create('export', $descriptor->slug(), 'export', (string) $this->user->id(), impersonatorId: $this->actor->impersonatorId);
            $this->queue->enqueue($this->jobs, $jobId, new ExportJob($jobId, $descriptor->slug(), $stored, (string) $this->user->id(), $format));

            return JsonResponse::create(['type' => 'job', 'jobId' => $jobId, 'message' => 'The export was queued.'], 202);
        }

        $csv = CsvWriter::document($descriptor, $fields, $this->service->stream($descriptor, $listRequest, total: $total));

        return FileResponse::fromContents($csv, 'text/csv; charset=utf-8', 200, $descriptor->slug() . '-' . gmdate('Ymd-His') . '.csv', ['Cache-Control' => 'no-store']);
    }

    /**
     * The first step of an import: the file's headers, a few rows, and
     * the mapping Vector suggests from the field names and labels.
     *
     * @return array<string, mixed>
     */
    #[Post('/{resource}/import/preview')]
    public function importPreview(string $resource, #[Body] ImportRequest $upload): array
    {
        $descriptor = $this->service->resource($resource);
        $this->service->permissions($descriptor)['create'] || throw new ForbiddenOperationException('This account may not create records here.');
        $reader = CsvReader::fromString((string) $upload->file->getStream());

        return [
            'headers' => $reader->headers(),
            'rows' => $reader->rowCount(),
            'sample' => $reader->sample(),
            'mapping' => $this->mapper->suggest($descriptor, $reader->headers()),
        ];
    }

    /**
     * Stores the upload on a job and queues it; `mapping` is a JSON
     * object of CSV header to field name.
     */
    #[Post('/{resource}/import')]
    public function import(string $resource, #[Body] ImportRequest $upload): ResponseInterface
    {
        $descriptor = $this->service->resource($resource);
        $permissions = $this->service->permissions($descriptor);
        // each row is still authorized as the create or update it becomes
        match ($upload->mode) {
            'update' => $permissions['update'] || throw new ForbiddenOperationException('This account may not update records here.'),
            'upsert' => $permissions['create'] || $permissions['update'] || throw new ForbiddenOperationException('This account may not create or update records here.'),
            default => $permissions['create'] || throw new ForbiddenOperationException('This account may not create records here.'),
        };
        $reason = $this->queue->unavailableReason();

        if ($reason !== null) {
            throw new ForbiddenOperationException($reason);
        }

        try {
            $mapping = json_decode($upload->mapping, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::fromViolations([new Violation(['mapping'], 'invalid_json', 'must be a JSON object.')]);
        }

        if (!is_array($mapping)) {
            throw ValidationException::fromViolations([new Violation(['mapping'], 'type_mismatch', 'must be a JSON object.')]);
        }

        $mapping = $this->mapper->validateMapping($descriptor, $mapping, $upload->mode, $upload->key);

        if ($mapping === []) {
            throw ValidationException::fromViolations([new Violation(['mapping'], 'required', 'must map at least one column.')]);
        }

        $contents = (string) $upload->file->getStream();
        $reader = CsvReader::fromString($contents); // refuses a file without a header or over the row cap
        $fileName = mb_substr($upload->file->getClientFilename() ?? 'import.csv', 0, 255); // the column's width
        $jobId = $this->jobs->create('import', $descriptor->slug(), 'import', (string) $this->user->id(), $reader->rowCount(), $contents, $fileName, 'text/csv', $this->actor->impersonatorId);
        $this->queue->enqueue($this->jobs, $jobId, new ImportJob($jobId, $descriptor->slug(), $mapping, (string) $this->user->id(), $upload->mode, $upload->mode === 'create' || $upload->key === '' ? null : $upload->key));

        return JsonResponse::create(['type' => 'job', 'jobId' => $jobId, 'rows' => $reader->rowCount()], 202);
    }

    /**
     * The list's changeset: `{"changes": [{"id", "version", "values": {…}}]}`,
     * written in one transaction; answers the records as they are now.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    #[Post('/{resource}/changes')]
    public function changes(string $resource, ServerRequestInterface $request): array
    {
        $descriptor = $this->service->resource($resource);
        $changes = $this->body($request)['changes'] ?? null;
        $valid = is_array($changes) && array_is_list($changes) && array_all($changes, static fn (mixed $c): bool => is_array($c)
            && (is_int($c['id'] ?? null) || is_string($c['id'] ?? null))
            && is_array($c['values'] ?? null) && !(array_is_list($c['values']) && $c['values'] !== [])
            && (!isset($c['version']) || is_int($c['version']) || is_string($c['version'])));

        if (!$valid) {
            throw ValidationException::fromViolations([new Violation(['changes'], 'type_mismatch', 'must be a list of {id, version, values} objects.')]);
        }

        /** @var list<array{id: int|string, version?: int|string|null, values: array<string, mixed>}> $changes */
        return ['data' => $this->writer->applyChanges($descriptor, $changes)];
    }

    /**
     * Stores one file for a file or image field (multipart `file`) and
     * answers the key to write into the field.
     *
     * @return array{key: string, name: string|null, size: int, type: string}
     */
    #[Post('/{resource}/fields/{field}/upload')]
    public function upload(string $resource, string $field, #[Body] \Kinetis\Vector\Files\UploadRequest $upload): array
    {
        return $this->files->upload($this->service->resource($resource), $field, $upload->file);
    }

    /** One record's stored file: an image inline, anything else as a download. */
    #[Get('/{resource}/{id}/files/{field}')]
    public function file(string $resource, string $id, string $field, ServerRequestInterface $request): ResponseInterface
    {
        // ?key= serves an image a rich-text document of the record shows from this field's uploads
        $key = $request->getQueryParams()['key'] ?? null;

        return $this->files->download($this->service->resource($resource), $id, $field, is_string($key) && $key !== '' ? $key : null);
    }

    /**
     * Adds records to a many-to-many relation: `{"ids": [...]}`. Each
     * added record is authorized by the policy's attach().
     *
     * @return array<string, mixed>
     */
    #[Post('/{resource}/{id}/{relation}/attach')]
    public function attach(string $resource, string $id, string $relation, ServerRequestInterface $request): array
    {
        return $this->membership($resource, $id, $relation, $request, 'attach');
    }

    /**
     * Removes records from a many-to-many relation: `{"ids": [...]}`.
     * Each removed record is authorized by the policy's detach().
     *
     * @return array<string, mixed>
     */
    #[Post('/{resource}/{id}/{relation}/detach')]
    public function detach(string $resource, string $id, string $relation, ServerRequestInterface $request): array
    {
        return $this->membership($resource, $id, $relation, $request, 'detach');
    }

    /**
     * @param 'attach'|'detach' $verb
     * @return array<string, mixed>
     */
    private function membership(string $resource, string $id, string $relation, ServerRequestInterface $request, string $verb): array
    {
        $descriptor = $this->service->resource($resource);
        $ids = $this->body($request)['ids'] ?? null;

        if (!is_array($ids) || !array_is_list($ids) || array_any($ids, static fn (mixed $v): bool => !is_int($v) && !is_string($v))) {
            throw ValidationException::fromViolations([new Violation(['ids'], 'type_mismatch', 'must be a list of identifiers.')]);
        }

        /** @var list<int|string> $ids */
        $record = $verb === 'attach'
            ? $this->writer->attach($descriptor, $id, $relation, $ids)
            : $this->writer->detach($descriptor, $id, $relation, $ids);

        return ['data' => $record, 'permissions' => $this->service->recordPermissions($descriptor, $record)];
    }

    /** @return array{data: list<array<string, mixed>>} */
    #[Get('/{resource}/{id}/audit')]
    public function audit(string $resource, string $id): array
    {
        $descriptor = $this->service->resource($resource);
        $this->service->show($descriptor, $id); // exists and is viewable

        $visible = $descriptor->fields();

        // an entry keeps only the fields this user may see
        return ['data' => array_map(static function (array $entry) use ($visible): array {
            $entry['changes'] = array_intersect_key($entry['changes'], $visible);

            return $entry;
        }, $this->audit->trail($descriptor->slug(), $id))];
    }

    /** @return array{data: list<array<string, mixed>>} */
    #[Get('/{resource}/{id}/{relation}/rows')]
    public function rows(string $resource, string $id, string $relation): array
    {
        return ['data' => $this->service->rows($this->service->resource($resource), $id, $relation)];
    }

    /** @return array<string, mixed> */
    #[Post('/{resource}/{id}/audit/{entry}/revert')]
    public function revert(string $resource, string $id, string $entry): array
    {
        $descriptor = $this->service->resource($resource);

        if (preg_match('/^[1-9][0-9]{0,17}$/', $entry) !== 1) {
            throw new RecordNotFoundException("No history entry {$entry}.");
        }

        $record = $this->writer->revert($descriptor, $id, (int) $entry);

        return ['data' => $record, 'permissions' => $this->service->recordPermissions($descriptor, $record)];
    }

    /** @return array{data: array<string, mixed>} */
    #[Get('/{resource}/widgets/{widget}')]
    public function widget(string $resource, string $widget, ServerRequestInterface $request): array
    {
        $descriptor = $this->service->resource($resource);
        $this->service->permissions($descriptor)['viewAny'] || throw new ForbiddenOperationException('This account may not view this resource.');
        $compiled = $descriptor->widget($widget) ?? throw WidgetRunner::notFound($widget);
        $range = $request->getQueryParams()['range'] ?? null;

        $locale = $this->locales->resolve((string) $this->user->id())['locale'];

        return ['data' => $this->labels->document($this->widgets->run($compiled, is_string($range) && $range !== '' ? $range : null, "resources/{$resource}"), $locale)];
    }


    /** Runs an action on `ids`, on `all: {filter, q}`, or on nothing when the action is standalone. */
    #[Post('/{resource}/actions/{action}')]
    public function action(string $resource, string $action, ServerRequestInterface $request): ResponseInterface
    {
        $descriptor = $this->service->resource($resource);
        $response = $this->actions->run($descriptor, $action, $this->body($request));

        if ($response->type === 'download') {
            return FileResponse::fromContents((string) $response->contents, (string) $response->contentType, 200, (string) $response->filename, ['Cache-Control' => 'no-store']);
        }

        return JsonResponse::create($response->toArray(), match ($response->type) {
            'danger' => 422,
            'job' => 202,
            default => 200,
        });
    }

    /** @return array<string, mixed> */
    #[Get('/{resource}/fields/{field}/options')]
    public function options(string $resource, string $field, ServerRequestInterface $request): array
    {
        $descriptor = $this->service->resource($resource);
        $params = $request->getQueryParams();
        $search = is_string($params['q'] ?? null) ? trim($params['q']) : '';
        $ids = is_string($params['ids'] ?? null) ? array_values(array_filter(array_map('trim', explode(',', $params['ids'])))) : [];
        // depends[team]=3: the form's values a dependent choice list narrows by
        $depends = array_filter(is_array($params['depends'] ?? null) ? $params['depends'] : [], static fn (mixed $v, mixed $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);

        return ['data' => $this->service->options($descriptor, $field, $search, $ids, depends: $depends)];
    }

    /** @return array<string, mixed> */
    #[Get('/{resource}/{id}')]
    public function show(string $resource, string $id): array
    {
        $descriptor = $this->service->resource($resource);
        $record = $this->service->show($descriptor, $id);

        return ['data' => $record, 'permissions' => $this->service->recordPermissions($descriptor, $record)];
    }

    /** @return array<string, mixed> */
    #[Get('/{resource}/{id}/{relation}')]
    public function related(string $resource, string $id, string $relation, ServerRequestInterface $request): array
    {
        $descriptor = $this->service->resource($resource);
        $field = $descriptor->field($relation);
        $target = $field !== null && isset($field['relation']) ? $this->service->resource($field['relation']['resource']) : $descriptor;

        return $this->service->related($descriptor, $id, $relation, ListRequest::fromQueryParams($request->getQueryParams(), $target, $this->converter));
    }

    #[Post('/{resource}', status: 201)]
    public function create(string $resource, ServerRequestInterface $request): ResponseInterface
    {
        $descriptor = $this->service->resource($resource);
        $record = $this->writer->create($descriptor, $this->body($request));

        return JsonResponse::create(['data' => $record, 'permissions' => $this->service->recordPermissions($descriptor, $record)], 201);
    }

    /** @return array<string, mixed> */
    #[Patch('/{resource}/{id}')]
    public function update(string $resource, string $id, ServerRequestInterface $request): array
    {
        $descriptor = $this->service->resource($resource);
        $ifMatch = trim($request->getHeaderLine('If-Match'), " \t\"");
        $record = $this->writer->update($descriptor, $id, $this->body($request), $ifMatch === '' ? null : $ifMatch);

        return ['data' => $record, 'permissions' => $this->service->recordPermissions($descriptor, $record)];
    }

    /** Deletes — into the trash on a soft-deleting resource unless `?force=1`; a hard-deleting resource removes the row either way. */
    #[Delete('/{resource}/{id}', status: 204)]
    public function delete(string $resource, string $id, ServerRequestInterface $request): ResponseInterface
    {
        $descriptor = $this->service->resource($resource);
        $force = $descriptor->softDelete() !== null && in_array($request->getQueryParams()['force'] ?? null, ['1', 'true'], true);
        $this->writer->delete($descriptor, $id, $force);

        return PlainTextResponse::create('', 204);
    }

    /**
     * Restores one record from the trash.
     *
     * @return array<string, mixed>
     */
    #[Post('/{resource}/{id}/restore')]
    public function restore(string $resource, string $id): array
    {
        $descriptor = $this->service->resource($resource);
        $this->writer->batch($descriptor, [$id], 'restore');
        $record = $this->service->show($descriptor, $id);

        return ['data' => $record, 'permissions' => $this->service->recordPermissions($descriptor, $record)];
    }

    /**
     * A bulk delete, restore or forceDelete over `ids` or over
     * `all: {filter, q}` — every record the selection matches, within
     * the resource's bulk limit.
     *
     * @return array<string, int>
     */
    #[Post('/{resource}/batch')]
    public function batch(string $resource, ServerRequestInterface $request): array
    {
        $descriptor = $this->service->resource($resource);
        $body = $this->body($request);
        $operation = $body['action'] ?? null;

        if (!in_array($operation, ['delete', 'restore', 'forceDelete', 'update'], true)) {
            throw ValidationException::fromViolations([new Violation(['action'], 'invalid_choice', 'must be delete, restore, forceDelete or update.')]);
        }

        $fields = $body['fields'] ?? [];

        if ($operation === 'update' && (!is_array($fields) || array_is_list($fields) && $fields !== [])) {
            throw ValidationException::fromViolations([new Violation(['fields'], 'type_mismatch', 'must be an object of field values.')]);
        }

        $all = $body['all'] ?? null;

        if (is_array($all)) {
            $ids = $this->service->matchingIds($descriptor, ListRequest::fromSelection($all, $descriptor, $this->converter));
        } else {
            $ids = $body['ids'] ?? null;

            if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > $descriptor->bulkLimit() || array_any($ids, static fn (mixed $id): bool => !is_int($id) && !is_string($id))) {
                throw ValidationException::fromViolations([new Violation(['ids'], 'type_mismatch', "must be a list of 1 to {$descriptor->bulkLimit()} identifiers, or pass all: {filter, q}.")]);
            }
        }

        if ($operation === 'update') {
            /** @var array<string, mixed> $fields */
            $affected = $this->writer->updateMany($descriptor, array_map(strval(...), $ids), $fields);

            return ['affected' => $affected, 'deleted' => 0];
        }

        /** @var 'delete'|'restore'|'forceDelete' $operation */
        $affected = $this->writer->batch($descriptor, array_map(strval(...), $ids), $operation);

        return ['affected' => $affected, 'deleted' => $operation === 'delete' ? $affected : 0];
    }

    /**
     * The JSON object of the request. Raw JSON stays on the staged body
     * stream; only #[Body] hydration parses it, and no fixed DTO fits.
     *
     * @return array<array-key, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();

        if (trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::fromViolations([new Violation([], 'invalid_json', 'The request body is not valid JSON.')]);
        }

        if (!is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw ValidationException::fromViolations([new Violation([], 'type_mismatch', 'The request body must be a JSON object.')]);
        }

        return $decoded;
    }
}
