<?php

declare(strict_types=1);

namespace Kinetis\Vector\Mcp;

use Kinetis\Authorization\Exception\AuthorizationException;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Mcp\Attributes\McpResource;
use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\McpProtocol\ToolResult;
use Kinetis\Validation\Constraints\GreaterThan;
use Kinetis\Validation\Constraints\LessThanOrEqual;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\ObjectMap;
use Kinetis\Vector\Actions\ActionRunner;
use Kinetis\Vector\Auth\VectorAccessInterface;
use Kinetis\Vector\Exception\VectorException;
use Kinetis\Vector\Query\ListRequest;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Widgets\WidgetRunner;
use Kinetis\Vector\Write\RecordWriter;
use Kinetis\Vector\Write\ValueConverter;

/**
 * The admin API as MCP tools: the same services the controllers call,
 * the same policies, the same list grammar. Every tool injects
 * CurrentUserInterface, so over stdio — where no middleware runs — the
 * class cannot be built and every tool fails closed; over HTTP the
 * application's middleware in the `mcp` group identifies the caller,
 * and the access policy is asked here as the `vector` group would.
 */
final readonly class VectorTools
{
    public function __construct(
        private CurrentUserInterface $user,
        private VectorAccessInterface $access,
        private VectorRegistry $registry,
        private ResourceService $reader,
        private RecordWriter $writer,
        private ActionRunner $actions,
        private WidgetRunner $widgets,
        private ValueConverter $converter,
        private \Kinetis\Vector\Translation\LocaleResolver $locales,
        private \Kinetis\Vector\Translation\Labels $labels,
    ) {}

    /** @return array<string, mixed>|ToolResult */
    #[McpTool(name: 'vector_resources', description: 'List the admin resources this caller may see: slug, label and group. Call vector_describe with a slug for its fields, filters and actions')]
    public function resources(): array|ToolResult
    {
        return $this->guarded(function (): array {
            $locale = $this->locales->resolve((string) $this->user->id())['locale'];
            $resources = [];

            foreach ($this->reader->resources() as $slug => $descriptor) {
                if ($this->reader->permissions($descriptor)['viewAny']) {
                    $public = $descriptor->toArray();
                    $resources[] = ['slug' => $slug, 'label' => $public['label'], 'group' => $public['group'] ?? null];
                }
            }

            // an index to pick from: one resource's detail is vector_describe's
            return ['resources' => $this->labels->document($resources, $locale)];
        });
    }

    /** @return array<string, mixed>|ToolResult */
    #[McpTool(name: 'vector_describe', description: 'Describe one resource for reading and writing its records: each field with its type, whether a create must send it (required), whether create or update may write it (writable), its rules, choices, relation and the filter operators vector_query accepts; the actions with their dialog fields; and what this caller may do')]
    public function describe(string $resource): array|ToolResult
    {
        return $this->guarded(function () use ($resource): array {
            $descriptor = $this->reader->resource($resource);
            $permissions = $this->reader->permissions($descriptor);
            $permissions['viewAny'] || throw new AuthorizationException('This account may not view this resource.');

            // in the language this user chose in the admin, like the schema resource
            return $this->labels->document(ResourceDescription::of($descriptor, $permissions), $this->locales->resolve((string) $this->user->id())['locale']);
        });
    }

    /**
     * @param array<string, mixed> $filter field => value, or field => {op: value}
     * @return array<string, mixed>|ToolResult
     */
    #[McpTool(name: 'vector_query', description: 'List records of a resource with the admin list grammar: filter {field: value | {op: value}, or: [filter, ...], and: [filter, ...]}, q, sort "-field,other", page, perPage')]
    public function query(
        string $resource,
        #[ObjectMap] array $filter = [],
        string $q = '',
        string $sort = '',
        #[GreaterThan(0)] int $page = 1,
        #[GreaterThan(0), LessThanOrEqual(200)] int $perPage = 25,
    ): array|ToolResult {
        return $this->guarded(function () use ($resource, $filter, $q, $sort, $page, $perPage): array {
            $descriptor = $this->reader->resource($resource);
            $params = ['page' => $page, 'perPage' => $perPage, 'filter' => self::stringify($filter), 'q' => $q, 'sort' => $sort];

            return $this->reader->list($descriptor, ListRequest::fromQueryParams($params, $descriptor, $this->converter, $descriptor->maxPerPage()));
        });
    }

    /** @return array<string, mixed>|ToolResult */
    #[McpTool(name: 'vector_get', description: 'Read one record of a resource by id, with its permissions')]
    public function get(string $resource, string $id): array|ToolResult
    {
        return $this->guarded(function () use ($resource, $id): array {
            $descriptor = $this->reader->resource($resource);
            $record = $this->reader->show($descriptor, $id);

            return ['data' => $record, 'permissions' => $this->reader->recordPermissions($descriptor, $record)];
        });
    }

    /**
     * @param array<string, mixed> $values field => value
     * @return array<string, mixed>|ToolResult
     */
    #[McpTool(name: 'vector_create', description: 'Create a record from values {field: value}, validated like the admin form')]
    public function create(string $resource, #[ObjectMap] array $values): array|ToolResult
    {
        return $this->guarded(fn (): array => ['data' => $this->writer->create($this->reader->resource($resource), $values)]);
    }

    /**
     * @param array<string, mixed> $values field => value, only the fields to change
     * @return array<string, mixed>|ToolResult
     */
    #[McpTool(name: 'vector_update', description: 'Update the fields in values {field: value} of a record; pass the version you read to refuse a stale write')]
    public function update(string $resource, string $id, #[ObjectMap] array $values, string $version = ''): array|ToolResult
    {
        return $this->guarded(fn (): array => ['data' => $this->writer->update($this->reader->resource($resource), $id, $values, $version === '' ? null : $version)]);
    }

    /** @return array<string, mixed>|ToolResult */
    #[McpTool(name: 'vector_delete', description: 'Delete a record: into the trash on a soft-deleting resource unless force is true')]
    public function delete(string $resource, string $id, bool $force = false): array|ToolResult
    {
        return $this->guarded(function () use ($resource, $id, $force): array {
            $this->writer->delete($this->reader->resource($resource), $id, $force);

            return ['deleted' => $id];
        });
    }

    /**
     * @param list<string> $ids
     * @param array<string, mixed> $values the action dialog's field => value
     * @return array<string, mixed>|ToolResult
     */
    #[McpTool(name: 'vector_run_action', description: 'Run a resource action on record ids (empty for a standalone action) with its dialog values {field: value}')]
    public function runAction(string $resource, string $action, #[\Kinetis\Validation\ListOf('string')] array $ids = [], #[ObjectMap] array $values = []): array|ToolResult
    {
        return $this->guarded(function () use ($resource, $action, $ids, $values): array {
            $response = $this->actions->run($this->reader->resource($resource), $action, ['ids' => $ids, 'fields' => $values]);

            if ($response->type === 'download') {
                // a file is bytes, and a tool result is JSON text: base64 carries a workbook as well as a CSV
                return ['type' => 'download', 'filename' => $response->filename, 'contentType' => $response->contentType, 'encoding' => 'base64', 'contents' => base64_encode((string) $response->contents)];
            }

            return $response->toArray();
        });
    }

    /** @return array<string, mixed>|ToolResult */
    #[McpTool(name: 'vector_widget', description: 'Compute a dashboard widget (dashboard + widget) or a resource widget (resource + widget) for a range such as 30d')]
    public function widget(string $widget, string $dashboard = '', string $resource = '', string $range = ''): array|ToolResult
    {
        return $this->guarded(function () use ($widget, $dashboard, $resource, $range): array {
            if ($resource !== '') {
                $descriptor = $this->reader->resource($resource);
                $this->reader->permissions($descriptor)['viewAny'] || throw new AuthorizationException('This account may not view this resource.');
                $compiled = $descriptor->widget($widget) ?? throw WidgetRunner::notFound($widget);

                return ['data' => $this->widgets->run($compiled, $range === '' ? null : $range, "resources/{$resource}")];
            }

            $page = $this->registry->dashboard($dashboard === '' ? 'main' : $dashboard);

            if (!$this->widgets->dashboardVisible($page)) {
                throw new AuthorizationException('This dashboard is not available to this account.');
            }

            foreach ($page['widgets'] as $compiled) {
                if ($compiled['name'] === $widget) {
                    return ['data' => $this->widgets->run($compiled, $range === '' ? null : $range, "dashboards/{$page['name']}")];
                }
            }

            throw WidgetRunner::notFound($widget);
        });
    }

    /** @return array<string, mixed> */
    #[McpResource(uri: 'vector://schema', name: 'vector-schema', description: 'The admin descriptor: every resource, field, action and dashboard', mimeType: 'application/json')]
    public function schema(): array
    {
        if (!$this->access->canAccessVector($this->user)) {
            return ['error' => 'This account has no access to the admin.'];
        }

        $resources = [];

        foreach ($this->reader->resources() as $slug => $descriptor) {
            if ($this->reader->permissions($descriptor)['viewAny']) {
                $resources[$slug] = $descriptor->toArray();
            }
        }

        // in the language this user chose in the admin, else the default
        $locale = $this->locales->resolve((string) $this->user->id())['locale'];

        $dashboards = array_keys(array_filter($this->registry->dashboards(), $this->widgets->dashboardVisible(...)));

        return ['version' => $this->registry->toArray()['version'], 'locale' => $locale, 'resources' => $this->labels->document($resources, $locale), 'dashboards' => $dashboards];
    }

    /**
     * Runs a tool body under the access policy, turning every refusal
     * Vector or the framework expresses into a tool error the agent can
     * read, and nothing else.
     *
     * @param \Closure(): array<string, mixed> $body
     * @return array<string, mixed>|ToolResult
     */
    private function guarded(\Closure $body): array|ToolResult
    {
        if (!$this->access->canAccessVector($this->user)) {
            return ToolResult::error('This account has no access to the admin.');
        }

        try {
            return $body();
        } catch (AuthorizationException $e) {
            return ToolResult::error($e->getMessage());
        } catch (VectorException $e) {
            return ToolResult::error($e->getMessage());
        } catch (ValidationException $e) {
            return ToolResult::error(json_encode(['errors' => array_map(static fn (\Kinetis\Validation\Violation $v): array => ['path' => $v->path, 'code' => $v->code, 'message' => $v->message], $e->violations)], JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Filter values as the list grammar reads them from a query string: text.
     *
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    private static function stringify(array $filter): array
    {
        $out = [];

        foreach ($filter as $field => $spec) {
            if (in_array($field, ['or', 'and'], true) && is_array($spec)) {
                $out[$field] = array_map(static fn (mixed $child): array => self::stringify(is_array($child) ? $child : []), array_values($spec));
            } elseif (is_array($spec)) {
                $ops = [];

                foreach ($spec as $op => $value) {
                    $ops[$op] = is_array($value) ? implode(',', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value)) : (is_scalar($value) ? (string) $value : '');
                }

                $out[$field] = $ops;
            } else {
                $out[$field] = is_scalar($spec) ? (string) $spec : '';
            }
        }

        return $out;
    }
}
