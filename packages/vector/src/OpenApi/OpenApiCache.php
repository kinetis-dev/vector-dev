<?php

declare(strict_types=1);

namespace Kinetis\Vector\OpenApi;

use Kinetis\Validation\JsonSchema;
use Kinetis\Vector\Import\ImportRequest;

/**
 * The identity-independent parts of `GET /vector/openapi.json`, bound
 * once per worker on AppScope: the components every document shares,
 * and each compiled field's and action's schemas. The registry is
 * compiled and cannot change under a worker, and a policy only ever
 * removes a field — it never alters one — so a schema kept here is the
 * same for every identity.
 * Which fields, actions and resources a document names is decided per
 * request, by {@see OpenApiDocument}.
 *
 * The memo is per instance, not static: it lives and dies with the
 * worker's AppScope, and every value is computed without yielding, so
 * two requests on one worker cannot interleave inside a computation.
 */
final class OpenApiCache
{
    /** @var array<string, mixed>|null */
    private ?array $components = null;

    /** @var array<string, array<string, mixed>|null> */
    private array $schemas = [];

    /**
     * The schema stored under $key, computed by $build the first time.
     *
     * @param \Closure(): (array<string, mixed>|null) $build
     * @return array<string, mixed>|null
     */
    public function schema(string $key, \Closure $build): ?array
    {
        if (!array_key_exists($key, $this->schemas)) {
            $this->schemas[$key] = $build();
        }

        return $this->schemas[$key];
    }

    /**
     * The schemas and responses every document shares.
     *
     * @return array{schemas: array<string, mixed>, responses: array<string, mixed>}
     */
    public function components(): array
    {
        /** @var array{schemas: array<string, mixed>, responses: array<string, mixed>} */
        return $this->components ??= self::buildComponents();
    }

    /** @return array{schemas: array<string, mixed>, responses: array<string, mixed>} */
    private static function buildComponents(): array
    {
        $error = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]];

        return [
            'schemas' => [
                'Error' => [
                    'type' => 'object',
                    'properties' => ['error' => ['type' => 'string']],
                    'required' => ['error'],
                ],
                // the framework's RFC 9457 document for a refused value
                'Problem' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'status' => ['type' => 'integer'],
                        'detail' => ['type' => 'string'],
                        'errors' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'array', 'items' => ['type' => ['string', 'integer']]],
                                'code' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                                'parameters' => ['type' => 'object'],
                            ],
                            'required' => ['path', 'code', 'message'],
                        ]],
                    ],
                    'required' => ['type', 'title', 'status'],
                ],
                'RecordPermissions' => [
                    'type' => 'object',
                    'properties' => [
                        'update' => ['type' => 'boolean'],
                        'delete' => ['type' => 'boolean'],
                        'restore' => ['type' => 'boolean'],
                        'forceDelete' => ['type' => 'boolean'],
                        'actions' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['update', 'delete', 'restore', 'forceDelete', 'actions'],
                ],
                'ActionResult' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['enum' => ['message', 'danger', 'redirect', 'url', 'job']],
                        'message' => ['type' => ['string', 'null']],
                        'url' => ['type' => ['string', 'null']],
                        'affected' => ['type' => 'integer'],
                        'jobId' => ['type' => ['integer', 'null']],
                    ],
                    'required' => ['type', 'message', 'url', 'affected', 'jobId'],
                ],
                'QueuedJob' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['const' => 'job'],
                        'jobId' => ['type' => 'integer'],
                        'message' => ['type' => 'string'],
                        'rows' => ['type' => 'integer'],
                    ],
                    'required' => ['type', 'jobId'],
                ],
                'Option' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => ['integer', 'string']],
                        'label' => ['type' => 'string'],
                        'subtitle' => ['type' => ['string', 'null']],
                        'image' => ['type' => ['string', 'null']],
                    ],
                    'required' => ['id', 'label'],
                ],
                'ImportPreview' => [
                    'type' => 'object',
                    'properties' => [
                        'headers' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'rows' => ['type' => 'integer'],
                        'sample' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]],
                        'mapping' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                    ],
                    'required' => ['headers', 'rows', 'sample', 'mapping'],
                ],
                'ImportRequest' => JsonSchema::forClass(ImportRequest::class),
            ],
            'responses' => [
                'Unauthenticated' => ['description' => 'No identity.', 'content' => $error],
                'Forbidden' => ['description' => 'Not admitted, or refused by a policy.', 'content' => $error],
                'NotFound' => ['description' => 'Unknown resource or record.', 'content' => $error],
                'Conflict' => ['description' => 'A stale version.', 'content' => $error],
                'Unprocessable' => [
                    'description' => 'A refused value, as an RFC 9457 document; or an invalid list request, as an error.',
                    'content' => [
                        'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']],
                        ...$error,
                    ],
                ],
            ],
        ];
    }
}
