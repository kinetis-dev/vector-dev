<?php

declare(strict_types=1);

namespace Kinetis\Vector\OpenApi;

use Kinetis\Vector\Http\VisibleResources;
use Kinetis\Vector\Jobs\VectorQueue;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\VectorDefaults;
use Kinetis\Vector\Write\RecordInput;

/**
 * The OpenAPI 3.1 document of Vector's REST API as this request's user
 * may use it: concrete paths for each resource they may list, built
 * from the compiled registry through the same overlay the descriptor
 * publishes ({@see VisibleResources}). A resource they may not list, a
 * field the policy hides and an action they may not run are absent,
 * not marked. Create and export appear only where permitted; update
 * and delete are decided per record, so they appear wherever the
 * resource does, as the descriptor's permissions say.
 *
 * The schemas themselves come from {@see OpenApiCache}; this class only
 * chooses which of them this identity sees.
 */
final readonly class OpenApiDocument
{
    private const string ACTION_RESULT = '#/components/schemas/ActionResult';

    public function __construct(
        private VisibleResources $resources,
        private ResourceService $service,
        private OpenApiCache $cache,
        private VectorQueue $queue,
        private VectorDefaults $defaults,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $components = $this->cache->components();
        $schemas = $components['schemas'];
        $paths = [];
        $tags = [];
        $visible = $this->resources->all();

        foreach ($visible as $slug => ['descriptor' => $descriptor, 'permissions' => $permissions]) {
            $tags[] = ['name' => $slug, 'description' => $descriptor->label()];
            $schemas["{$slug}.Record"] = $this->record($descriptor);
            $schemas["{$slug}.Update"] = $this->body($descriptor, 'update');

            if ($permissions['create']) {
                $schemas["{$slug}.Create"] = $this->body($descriptor, 'create');
            }

            foreach ($this->paths($descriptor, $permissions, array_keys($visible)) as $path => $operations) {
                $paths[$path] = $operations;
            }
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Vector REST API',
                'version' => (string) $this->service->registry()->toArray()['version'],
                'description' => 'The resources, fields and actions this identity may use. Every operation needs the application\'s Vector authentication.',
            ],
            'tags' => $tags,
            'paths' => $paths === [] ? new \stdClass() : $paths,
            'components' => ['schemas' => $schemas, 'responses' => $components['responses']],
        ];
    }

    /**
     * @param array<string, bool> $permissions
     * @param list<string> $listable the slugs this user may list
     * @return array<string, array<string, mixed>>
     */
    private function paths(ResourceDescriptor $descriptor, array $permissions, array $listable): array
    {
        $slug = $descriptor->slug();
        $base = "/vector/r/{$slug}";
        $record = ['$ref' => "#/components/schemas/{$slug}.Record"];
        $id = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => $descriptor->primaryKeyType() === 'int' ? ['type' => 'integer'] : ['type' => 'string']];
        $one = self::json(['type' => 'object', 'properties' => ['data' => $record, 'permissions' => ['$ref' => '#/components/schemas/RecordPermissions']], 'required' => ['data', 'permissions']]);
        $soft = $descriptor->softDelete() !== null;
        // what the descriptor publishes to this user: the searched and summarized fields it may see
        /** @var array{search: list<string>, summaries: list<string>} $table */
        $table = $this->resources->overlay($descriptor, $permissions)['table'];

        $paths = [];
        $paths[$base]['get'] = self::operation("{$slug}.list", "List {$descriptor->label()}", [
            ...$this->listParameters($descriptor, $table),
            ...$this->pageParameters($descriptor, $table),
        ], null, ['200' => ['description' => 'A page of records.', 'content' => self::json($this->page($record, $descriptor->withoutCount()))]]);
        $paths[$base]['get']['description'] = '`filter[field]=value` is `eq`. `filter[or][i]…` and `filter[and][i]…` hold alternatives and combined groups of these same conditions, four deep and 50 conditions at most.';

        if ($permissions['create']) {
            $paths[$base]['post'] = self::operation("{$slug}.create", "Create a {$descriptor->labelSingular()}", [], self::requestBody("#/components/schemas/{$slug}.Create"), [
                '201' => ['description' => 'The record as written.', 'content' => $one],
            ], 409);
        }

        $version = $descriptor->versionColumn() === null ? [] : [['name' => 'If-Match', 'in' => 'header', 'required' => false, 'description' => 'The record\'s `_version`; a stale one answers 409.', 'schema' => ['type' => 'string']]];
        $force = $soft ? [['name' => 'force', 'in' => 'query', 'required' => false, 'description' => 'Delete for good instead of trashing.', 'schema' => ['enum' => ['1', 'true']]]] : [];

        $paths["{$base}/{id}"] = [
            'get' => self::operation("{$slug}.show", "Show a {$descriptor->labelSingular()}", [$id], null, ['200' => ['description' => 'The record and this user\'s permissions on it.', 'content' => $one]]),
            'patch' => self::operation("{$slug}.update", "Update a {$descriptor->labelSingular()}", [$id, ...$version], self::requestBody("#/components/schemas/{$slug}.Update"), [
                '200' => ['description' => 'The record as written.', 'content' => $one],
            ], 409),
            'delete' => self::operation("{$slug}.delete", $soft ? "Trash a {$descriptor->labelSingular()}" : "Delete a {$descriptor->labelSingular()}", [$id, ...$force], null, ['204' => ['description' => 'Deleted.']]),
        ];

        if ($soft) {
            $paths["{$base}/{id}/restore"]['post'] = self::operation("{$slug}.restore", "Restore a {$descriptor->labelSingular()}", [$id], null, ['200' => ['description' => 'The restored record.', 'content' => $one]]);
        }

        if ($permissions['export']) {
            $formats = $this->queue->available() ? $this->defaults->exportFormats : ['csv'];
            $paths["{$base}/export"]['get'] = self::operation("{$slug}.export", "Export {$descriptor->label()}", [
                ...$this->listParameters($descriptor, $table),
                ['name' => 'format', 'in' => 'query', 'required' => false, 'schema' => ['enum' => $formats, 'default' => 'csv']],
            ], null, [
                '200' => ['description' => 'The file.', 'content' => ['text/csv' => ['schema' => ['type' => 'string']]]],
                '202' => ['description' => 'Above the export limit, or XLSX: a job whose download is the file.', 'content' => self::json(['$ref' => '#/components/schemas/QueuedJob'])],
            ]);
        }

        $multipart = ['required' => true, 'content' => ['multipart/form-data' => ['schema' => ['$ref' => '#/components/schemas/ImportRequest']]]];

        // the preview needs create, as the controller does; the import, a queue and the mode's permission
        if ($permissions['create']) {
            $paths["{$base}/import/preview"]['post'] = self::operation("{$slug}.importPreview", "Preview an import of {$descriptor->label()}", [], $multipart, [
                '200' => ['description' => 'The file\'s headers, a sample and a suggested mapping.', 'content' => self::json(['$ref' => '#/components/schemas/ImportPreview'])],
            ]);
        }

        if ($this->queue->available() && ($permissions['create'] || $permissions['update'])) {
            $paths["{$base}/import"]['post'] = self::operation("{$slug}.import", "Import {$descriptor->label()}", [], $multipart, [
                '202' => ['description' => 'The queued import.', 'content' => self::json(['$ref' => '#/components/schemas/QueuedJob'])],
            ]);
        }

        return [...$paths, ...$this->actionPaths($descriptor, $base), ...$this->optionPaths($descriptor, $base, $listable)];
    }

    /**
     * An operation for each action this user may run.
     *
     * @return array<string, array<string, mixed>>
     */
    private function actionPaths(ResourceDescriptor $descriptor, string $base): array
    {
        $paths = [];

        foreach ($this->service->visibleActions($descriptor, null) as $name) {
            $action = $descriptor->action($name);

            if ($action !== null) {
                $paths["{$base}/actions/{$name}"]['post'] = $this->action($descriptor, $action);
            }
        }

        return $paths;
    }

    /**
     * The choices of each relation input whose target this user may list.
     *
     * @param list<string> $listable the slugs this user may list
     * @return array<string, array<string, mixed>>
     */
    private function optionPaths(ResourceDescriptor $descriptor, string $base, array $listable): array
    {
        $paths = [];

        foreach ($descriptor->fields() as $name => $field) {
            $target = $field['relation']['resource'] ?? null;

            if (in_array($field['type'], ['belongsTo', 'manyToMany'], true) && in_array($target, $listable, true)) {
                $paths["{$base}/fields/{$name}/options"]['get'] = $this->options($descriptor->slug(), $name, $field);
            }
        }

        return $paths;
    }

    /**
     * The record as a list or a show carries it: each field it can
     * serialize for this user, plus the trash and version markers.
     *
     * @return array<string, mixed>
     */
    private function record(ResourceDescriptor $descriptor): array
    {
        $properties = [];

        foreach (array_unique([...$descriptor->indexFields(), ...$descriptor->showFields()]) as $name) {
            $schema = $this->fieldSchema($descriptor, $name, 'read');

            if ($schema !== null) {
                $properties[$name] = $schema;
            }
        }

        if ($descriptor->softDelete() !== null) {
            $properties['_trashed'] = ['type' => 'boolean', 'readOnly' => true];
        }

        if ($descriptor->versionColumn() !== null) {
            $properties['_version'] = ['type' => 'integer', 'readOnly' => true];
        }

        return [
            'type' => 'object',
            'description' => 'Every field a list or a show can carry; `columns` narrows a list\'s to those named, and the primary key is always there.',
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'required' => [$descriptor->primaryKeyField()],
        ];
    }

    /**
     * A create or update body: the fields that view writes, with their
     * rules; a create requires what the writer requires of a new record.
     *
     * @param 'create'|'update' $view
     * @return array<string, mixed>
     */
    private function body(ResourceDescriptor $descriptor, string $view): array
    {
        $properties = [];
        $required = [];

        foreach ($descriptor->fields() as $name => $field) {
            if (!in_array($view, $field['views'], true) || $field['readonly'] === true) {
                continue;
            }

            $schema = ($field['repeater'] ?? null) !== null ? $this->repeater($field) : $this->fieldSchema($descriptor, $name, 'write');

            if ($schema === null) {
                continue;
            }

            $properties[$name] = $schema;

            if ($view === 'create' && $field['hasDefault'] !== true && RecordInput::requiredOnCreate($field)) {
                $required[] = $name;
            }
        }

        if ($view === 'update' && $descriptor->versionColumn() !== null) {
            $properties['version'] = ['type' => ['integer', 'string'], 'description' => 'The record\'s `_version`, instead of If-Match.'];
        }

        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties, 'additionalProperties' => false];

        return $required === [] ? $schema : [...$schema, 'required' => $required];
    }

    /**
     * A repeater's rows: each the child's repeated fields as this user
     * sees the child, and the `id` of a row being kept.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function repeater(array $field): array
    {
        $child = $this->service->resource($field['relation']['resource']);
        $properties = ['id' => $child->primaryKeyType() === 'int' ? ['type' => 'integer'] : ['type' => 'string']];

        foreach ($field['repeater']['fields'] as $name) {
            $schema = $child->field($name) === null ? null : $this->fieldSchema($child, $name, 'write');

            if ($schema !== null) {
                $properties[$name] = $schema;
            }
        }

        return [
            'type' => 'array',
            'items' => ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false],
            'minItems' => $field['repeater']['min'],
            'maxItems' => $field['repeater']['max'],
        ];
    }

    /**
     * @param array<string, mixed> $action the compiled action
     * @return array<string, mixed>
     */
    private function action(ResourceDescriptor $descriptor, array $action): array
    {
        $slug = $descriptor->slug();
        $fields = $this->cache->schema("{$slug}.action.{$action['name']}", static fn (): array => self::actionFields($action));
        $properties = ['fields' => $fields];

        if ($action['standalone'] !== true) {
            $key = $descriptor->primaryKeyType() === 'int' ? ['type' => 'integer'] : ['type' => 'string'];
            $properties['ids'] = ['type' => 'array', 'items' => $key, 'minItems' => 1, 'maxItems' => $descriptor->bulkLimit()];
            $properties['all'] = [
                'type' => 'object',
                'description' => 'Every record the filter and search match, instead of `ids`.',
                'properties' => ['filter' => ['type' => 'object'], 'q' => ['type' => 'string']],
            ];
        }

        $operation = self::operation("{$slug}.actions.{$action['name']}", (string) $action['label'], [], [
            'required' => $action['standalone'] !== true,
            'content' => self::json(['type' => 'object', 'properties' => $properties]),
        ], [
            '200' => ['description' => 'What the client does next; a download answers the file itself.', 'content' => [
                ...self::json(['$ref' => self::ACTION_RESULT]),
                'application/octet-stream' => ['schema' => ['type' => 'string']],
            ]],
            '202' => ['description' => 'The queued action.', 'content' => self::json(['$ref' => self::ACTION_RESULT])],
        ]);

        // a refusal the action answers itself (`type: danger`) is a 422 too
        $operation['responses']['422'] = [
            'description' => 'A refused value or selection, or the action\'s own refusal (`type: danger`).',
            'content' => [
                'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']],
                'application/json' => ['schema' => ['oneOf' => [['$ref' => self::ACTION_RESULT], ['$ref' => '#/components/schemas/Error']]]],
            ],
        ];

        return $operation;
    }

    /**
     * An action dialog's fields as the body's `fields` object: a dialog's
     * field is required unless it may be empty or has a default.
     *
     * @param array<string, mixed> $action the compiled action
     * @return array<string, mixed>
     */
    private static function actionFields(array $action): array
    {
        $properties = [];
        $required = [];

        foreach ($action['fields'] as $field) {
            $schema = FieldSchemas::write($field);

            if ($schema !== null) {
                $properties[$field['name']] = $schema;

                if (($field['nullable'] ?? true) !== true && $field['hasDefault'] !== true) {
                    $required[] = $field['name'];
                }
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties, 'additionalProperties' => false];

        return $required === [] ? $schema : [...$schema, 'required' => $required];
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function options(string $slug, string $name, array $field): array
    {
        $parameters = [
            ['name' => 'q', 'in' => 'query', 'required' => false, 'description' => 'Search the target\'s title.', 'schema' => ['type' => 'string']],
            ['name' => 'ids', 'in' => 'query', 'required' => false, 'description' => 'Comma-separated identifiers to resolve instead.', 'schema' => ['type' => 'string']],
        ];
        $dependsOn = $field['relation']['dependsOn']['field'] ?? null;

        if (is_string($dependsOn)) {
            $parameters[] = ['name' => "depends[{$dependsOn}]", 'in' => 'query', 'required' => false, 'description' => "The form's value of {$dependsOn}, which narrows the choices.", 'schema' => ['type' => 'string']];
        }

        return self::operation("{$slug}.options.{$name}", "Choices for {$name}", $parameters, null, [
            '200' => ['description' => 'The matching records of the target.', 'content' => self::json([
                'type' => 'object',
                'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Option']]],
                'required' => ['data'],
            ])],
        ]);
    }

    /**
     * The selection a list and an export share: sort, filters, search,
     * grouping and columns, each limited to what the descriptor allows.
     *
     * @param array{search: list<string>, summaries: list<string>} $table the overlay's table
     * @return list<array<string, mixed>>
     */
    private function listParameters(ResourceDescriptor $descriptor, array $table): array
    {
        $sortable = [];
        $filters = [];

        foreach ($descriptor->fields() as $name => $field) {
            if ($field['sortable'] === true) {
                array_push($sortable, $name, "-{$name}");
            }

            foreach ($field['operators'] as $operator) {
                $filters[] = [
                    'name' => "filter[{$name}][{$operator}]",
                    'in' => 'query',
                    'required' => false,
                    'schema' => FieldSchemas::filterValue($field, $operator),
                ];
            }
        }

        $parameters = [];

        if ($sortable !== []) {
            $parameters[] = ['name' => 'sort', 'in' => 'query', 'required' => false, 'description' => 'Sortable fields, `-` for descending.', 'style' => 'form', 'explode' => false,
                'schema' => ['type' => 'array', 'items' => ['enum' => $sortable]]];
        }

        array_push($parameters, ...$filters);

        if ($descriptor->softDelete() !== null) {
            $parameters[] = ['name' => 'filter[trashed]', 'in' => 'query', 'required' => false, 'description' => 'Only trashed records, trashed ones too, or none (the default).', 'schema' => ['enum' => ['only', 'with', 'without']]];
        }

        if ($table['search'] !== []) {
            $parameters[] = ['name' => 'q', 'in' => 'query', 'required' => false, 'description' => 'Search over ' . implode(', ', $table['search']) . '.', 'schema' => ['type' => 'string', 'maxLength' => 200]];
        }

        if ($descriptor->groupable() !== []) {
            $parameters[] = ['name' => 'group', 'in' => 'query', 'required' => false, 'schema' => ['enum' => $descriptor->groupable()]];
        }

        $parameters[] = ['name' => 'columns', 'in' => 'query', 'required' => false, 'description' => 'Only these fields, plus what their display needs.', 'style' => 'form', 'explode' => false,
            'schema' => ['type' => 'array', 'items' => ['enum' => array_values(array_unique([...$descriptor->indexFields(), ...$descriptor->showFields()]))]]];

        return $parameters;
    }

    /**
     * @param array{search: list<string>, summaries: list<string>} $table
     * @return list<array<string, mixed>>
     */
    private function pageParameters(ResourceDescriptor $descriptor, array $table): array
    {
        $parameters = [
            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
            ['name' => 'perPage', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $descriptor->maxPerPage(), 'default' => $descriptor->perPage()]],
            ['name' => 'cursor', 'in' => 'query', 'required' => false, 'description' => 'Keyset pages by the identifier: empty for the first, then the previous page\'s `nextCursor`. Not with `sort`.', 'schema' => ['type' => 'string']],
        ];

        if ($table['summaries'] !== []) {
            $parameters[] = ['name' => 'summaries', 'in' => 'query', 'required' => false, 'description' => 'Include the summaries of ' . implode(', ', $table['summaries']) . '.', 'schema' => ['enum' => ['1', 'true']]];
        }

        return $parameters;
    }

    /**
     * The list's page: by offset, or under a cursor. A withoutCount()
     * resource's offset page has no total or last page and says whether
     * another page follows instead.
     *
     * @param array<string, string> $record
     * @return array<string, mixed>
     */
    private function page(array $record, bool $withoutCount): array
    {
        $data = ['type' => 'array', 'items' => $record];
        $offset = $withoutCount
            ? [
                'type' => 'object',
                'description' => 'Not counted: `total` and `lastPage` are null, and `hasMore` says whether another page follows.',
                'properties' => [
                    'data' => $data,
                    'currentPage' => ['type' => 'integer'],
                    'perPage' => ['type' => 'integer'],
                    'total' => ['type' => 'null'],
                    'lastPage' => ['type' => 'null'],
                    'hasMore' => ['type' => 'boolean'],
                    'summaries' => ['type' => 'object'],
                ],
                'required' => ['data', 'currentPage', 'perPage', 'total', 'lastPage', 'hasMore'],
            ]
            : [
                'type' => 'object',
                'properties' => [
                    'data' => $data,
                    'currentPage' => ['type' => 'integer'],
                    'perPage' => ['type' => 'integer'],
                    'total' => ['type' => 'integer'],
                    'lastPage' => ['type' => 'integer'],
                    'summaries' => ['type' => 'object'],
                ],
                'required' => ['data', 'currentPage', 'perPage', 'total', 'lastPage'],
            ];

        return ['oneOf' => [
            $offset,
            [
                'type' => 'object',
                'description' => 'Under a cursor.',
                'properties' => [
                    'data' => $data,
                    'nextCursor' => ['type' => ['string', 'null']],
                    'hasMore' => ['type' => 'boolean'],
                    'perPage' => ['type' => 'integer'],
                ],
                'required' => ['data', 'nextCursor', 'hasMore', 'perPage'],
            ],
        ]];
    }

    /**
     * One field's schema, kept for the worker's life; null when the
     * field has none in that direction.
     *
     * @param 'read'|'write' $direction
     * @return array<string, mixed>|null
     */
    private function fieldSchema(ResourceDescriptor $descriptor, string $name, string $direction): ?array
    {
        $field = $descriptor->field($name);

        if ($field === null) {
            return null;
        }

        return $this->cache->schema("{$descriptor->slug()}.{$direction}.{$name}", static fn (): ?array => $direction === 'read' ? FieldSchemas::read($field) : FieldSchemas::write($field));
    }

    /**
     * @param list<array<string, mixed>> $parameters
     * @param array<string, mixed>|null $body
     * @param array<int|string, array<string, mixed>> $responses status codes, which PHP keys as integers
     * @return array<string, mixed>
     */
    private static function operation(string $id, string $summary, array $parameters, ?array $body, array $responses, ?int $conflict = null): array
    {
        // `<slug>.<operation>`: the tag is the slug, which never holds a dot
        $operation = ['operationId' => $id, 'tags' => [explode('.', $id, 2)[0]], 'summary' => $summary];

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($body !== null) {
            $operation['requestBody'] = $body;
        }

        // a union, not a spread: the status codes are integer keys, which a spread renumbers
        $responses += [
            '401' => ['$ref' => '#/components/responses/Unauthenticated'],
            '403' => ['$ref' => '#/components/responses/Forbidden'],
            '404' => ['$ref' => '#/components/responses/NotFound'],
        ];

        if ($conflict !== null) {
            $responses[(string) $conflict] = ['$ref' => '#/components/responses/Conflict'];
        }

        $operation['responses'] = $responses + ['422' => ['$ref' => '#/components/responses/Unprocessable']];

        return $operation;
    }

    /** @return array<string, mixed> */
    private static function requestBody(string $ref): array
    {
        return ['required' => true, 'content' => self::json(['$ref' => $ref])];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, array<string, mixed>>
     */
    private static function json(array $schema): array
    {
        return ['application/json' => ['schema' => $schema]];
    }
}
