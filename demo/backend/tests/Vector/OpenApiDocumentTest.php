<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Auth\DemoActor;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\JsonResponse;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Translation\Labels;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\CompliantValidator;

/**
 * `GET /vector/openapi.json` as the seeded admin sees it: a valid
 * OpenAPI 3.1 document whose paths, parameters and bodies say what the
 * descriptor says, cached per identity, behind the `vector` group. The
 * descriptor's own answers are pinned to how they were built before
 * the two endpoints shared {@see \Kinetis\Vector\Http\VisibleResources}.
 */
final class OpenApiDocumentTest extends VectorTestCase
{
    public function testTheDocumentIsAValidOpenApi31Document(): void
    {
        $body = $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())
            ->assertOk()
            ->assertHeader('Content-Type')
            ->body();
        $document = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame('3.1.0', $document['openapi']);

        // the OpenAPI Initiative's own schema of a 3.1 document. Its `$dynamicRef: "#meta"`
        // has no other `$dynamicAnchor` in scope, so it means `$ref: "#/$defs/schema"`;
        // opis/json-schema resolves it to the document root instead, so it is spelled that way.
        $metaSchema = str_replace('"$dynamicRef": "#meta"', '"$ref": "#/$defs/schema"', (string) file_get_contents(__DIR__ . '/Fixtures/openapi-3.1-schema-2022-10-07.json'), $replaced);
        self::assertSame(4, $replaced);
        $validator = new CompliantValidator();
        $validator->setMaxErrors(5);
        $result = $validator->validate(json_decode($body, false, 512, JSON_THROW_ON_ERROR), $metaSchema);
        self::assertTrue($result->isValid(), $result->error() === null ? '' : json_encode((new ErrorFormatter())->format($result->error()), JSON_THROW_ON_ERROR));

        // and the same schema refuses a parameter that is nowhere
        $broken = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        $broken->paths->{'/vector/r/products'}->get->parameters[0]->in = 'nowhere';
        self::assertFalse($validator->validate($broken, $metaSchema)->isValid());

        // what the schema leaves to the document: every reference resolves, every
        // operation is named once, and every path parameter is declared
        $references = [];
        self::collect($document, static function (string $key, mixed $value) use (&$references): void {
            if ($key === '$ref' && is_string($value)) {
                $references[$value] = true;
            }
        });
        self::assertNotEmpty($references);

        foreach (array_keys($references) as $reference) {
            self::assertStringStartsWith('#/components/', $reference);
            $node = $document;

            foreach (explode('/', substr($reference, 2)) as $segment) {
                self::assertIsArray($node);
                self::assertArrayHasKey($segment, $node, "{$reference} resolves");
                $node = $node[$segment];
            }
        }

        $operationIds = [];

        foreach ($document['paths'] as $path => $operations) {
            preg_match_all('/\{(\w+)\}/', $path, $placeholders);

            foreach ($operations as $method => $operation) {
                $operationIds[] = $operation['operationId'];
                $inPath = array_column(array_filter($operation['parameters'] ?? [], static fn (array $p): bool => $p['in'] === 'path'), 'name');
                self::assertSame($placeholders[1], $inPath, "{$method} {$path} declares its path parameters");
                self::assertArrayHasKey('401', $operation['responses']);
            }
        }

        self::assertSame(array_unique($operationIds), $operationIds, 'operationIds are unique');
    }

    /**
     * Every resource, filter operator, sort, group, page bound, action and
     * permission the descriptor publishes for this identity is what the
     * document describes, and nothing else.
     */
    public function testEveryResourceIsDescribedAsTheDescriptorPublishesIt(): void
    {
        $schema = $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->json();
        $document = $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($schema);
        self::assertIsArray($document);

        self::assertSame(array_keys($schema['resources']), array_column($document['tags'], 'name'));
        self::assertSame((string) $schema['version'], $document['info']['version']);

        foreach ($schema['resources'] as $slug => $resource) {
            self::assertDescribes($document, $slug, $resource);
        }
    }

    public function testBodiesCarryTheFieldsRules(): void
    {
        $schemas = $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())->assertOk()->json()['components']['schemas'];

        $create = $schemas['products.Create'];
        self::assertFalse($create['additionalProperties']);
        self::assertContains('sku', $create['required']);
        self::assertContains('name', $create['required']);
        self::assertNotContains('releasedAt', $create['required'], 'nullable');
        self::assertNotContains('id', array_keys($create['properties']), 'an identity is never written');
        self::assertSame(40, $create['properties']['sku']['maxLength']);
        self::assertSame(['draft', 'active', 'archived'], $create['properties']['status']['enum']);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'integer']], $create['properties']['tags']);
        self::assertSame(['string', 'null'], $create['properties']['releasedAt']['type']);
        self::assertSame('date-time', $create['properties']['releasedAt']['format']);
        self::assertSame('email', $schemas['agents.Create']['properties']['email']['format']);
        self::assertSame('date', $schemas['agents.Create']['properties']['joinedOn']['format']);

        $update = $schemas['products.Update'];
        self::assertArrayNotHasKey('required', $update, 'an update sends only what changes');
        self::assertSame($create['properties']['sku'], $update['properties']['sku']);

        $record = $schemas['products.Record'];
        self::assertSame(['id'], $record['required']);
        self::assertSame(['type' => 'integer', 'readOnly' => true], $record['properties']['id']);
        self::assertSame(['type' => 'boolean', 'readOnly' => true], $record['properties']['_trashed']);

        // a belongs-to reads as {id, label}
        $product = $schemas['order-lines.Record']['properties']['product'];
        self::assertSame(['id', 'label'], $product['required']);
        self::assertSame(['type' => 'integer'], $product['properties']['id']);
    }

    public function testErrorsAreProblemDocumentsAt422AndErrorObjectsOtherwise(): void
    {
        $document = $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())->assertOk()->json();
        $components = $document['components'];

        self::assertSame(['error'], $components['schemas']['Error']['required']);
        self::assertSame(['type', 'title', 'status'], $components['schemas']['Problem']['required']);
        self::assertSame('#/components/schemas/Problem', $components['responses']['Unprocessable']['content']['application/problem+json']['schema']['$ref']);

        foreach (['Unauthenticated', 'Forbidden', 'NotFound', 'Conflict'] as $name) {
            self::assertSame(['application/json'], array_keys($components['responses'][$name]['content']));
            self::assertSame('#/components/schemas/Error', $components['responses'][$name]['content']['application/json']['schema']['$ref']);
        }

        $create = $document['paths']['/vector/r/products']['post'];
        self::assertSame('#/components/responses/Unprocessable', $create['responses']['422']['$ref']);
        self::assertSame('#/components/responses/Conflict', $create['responses']['409']['$ref']);
        self::assertSame(['201', '401', '403', '404', '409', '422'], array_map('strval', array_keys($create['responses'])));

        // the action's own refusal is a 422 too, in its own result shape
        $action = $document['paths']['/vector/r/products/actions/publish-products']['post'];
        self::assertArrayHasKey('application/problem+json', $action['responses']['422']['content']);
    }

    public function testTheGroupRefusesTheUnauthenticatedAndTheUnadmitted(): void
    {
        $anonymous = $this->client->get('/vector/openapi.json')->assertStatus(401);
        self::assertStringNotContainsString('openapi', $anonymous->body());
        $this->client->get('/vector/openapi.json', headers: ['Authorization' => 'Bearer not-a-token'])->assertStatus(401);
        $this->client->get('/vector/openapi.json', headers: $this->editorHeaders())->assertStatus(403);
    }

    public function testTheDocumentIsCachedPerIdentity(): void
    {
        $first = $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())
            ->assertOk()
            ->assertHeader('Cache-Control', 'private, no-cache');
        $etag = $first->getHeaderLine('ETag');
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag);

        // the same document again is the same tag
        self::assertSame($etag, $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())->getHeaderLine('ETag'));

        $notModified = $this->client->get('/vector/openapi.json', headers: [...$this->adminHeaders(), 'If-None-Match' => "\"stale\", {$etag}"])
            ->assertStatus(304)
            ->assertHeader('ETag', $etag);
        self::assertSame('', $notModified->body());

        $this->client->get('/vector/openapi.json', headers: [...$this->adminHeaders(), 'If-None-Match' => '"stale"'])->assertOk();

        // another identity's tag never matches this one's document
        $other = ['Authorization' => 'Bearer ' . $this->tokenFor('990002')];
        $theirs = $this->client->get('/vector/openapi.json', headers: [...$other, 'If-None-Match' => $etag])->assertOk();
        self::assertNotSame($etag, $theirs->getHeaderLine('ETag'));
        self::assertSame($first->json(), $theirs->json(), 'the same permissions, the same document');
    }

    /**
     * The descriptor exactly as SchemaController built it itself, before
     * the overlay moved to VisibleResources, recomputed here from
     * ResourceService: the whole document's resources and menu, and every
     * resource's own answer and tag, for the seeded admin in English and
     * a viewer of the tests' own in Hebrew.
     */
    public function testTheDescriptorIsBuiltAsItWasBeforeTheSharedOverlay(): void
    {
        foreach ([[1, $this->adminHeaders()], [990001, $this->headersIn('he')]] as [$userId, $headers]) {
            $whole = $this->client->get('/vector/schema', headers: $headers)->assertOk();
            $document = $whole->json();
            self::assertIsArray($document);
            $locale = $document['i18n']['locale'];
            self::assertSame($userId === 1 ? 'en' : 'he', $locale);
            ['resources' => $expected, 'menu' => $menu] = $this->overlays($userId, $locale);

            self::assertSame(array_keys($expected), array_keys($document['resources']));
            self::assertSame($menu, $document['menu'], "{$userId}: menu");

            foreach ($expected as $slug => $overlay) {
                $json = json_encode($overlay, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                self::assertSame(json_decode($json, true), $document['resources'][$slug], "{$userId}: {$slug} in the whole descriptor");

                $one = $this->client->get("/vector/schema/{$slug}", headers: $headers)->assertOk();
                self::assertSame((string) JsonResponse::create($overlay)->getBody(), $one->body(), "{$userId}: {$slug}, the same bytes");
                self::assertSame('"' . hash('xxh128', $json . '|' . $userId . '|' . $locale) . '"', $one->getHeaderLine('ETag'), "{$userId}: {$slug}, the same tag");
            }
        }
    }

    /**
     * Each resource the user may list, overlaid as SchemaController's own
     * private overlay() did, then translated; and the menu it built from
     * them, translated with the document.
     *
     * @return array{resources: array<string, array<string, mixed>>, menu: list<array<string, mixed>>}
     */
    private function overlays(int $userId, string $locale): array
    {
        $scope = $this->app->createRequestScope();

        try {
            $scope->instance(CurrentUserInterface::class, new DemoActor($userId, 'Test', true));
            $service = $scope->get(ResourceService::class);
            $labels = $scope->get(Labels::class);
            self::assertInstanceOf(ResourceService::class, $service);
            self::assertInstanceOf(Labels::class, $labels);
            self::assertInstanceOf(RequestScope::class, $scope);
            $overlays = [];
            $menu = [];

            foreach ($service->resources() as $slug => $descriptor) {
                $permissions = $service->permissions($descriptor);

                if ($permissions['viewAny'] !== true) {
                    continue;
                }

                $public = $descriptor->toArray();
                $visible = $service->visibleActions($descriptor, null);
                $public['actions'] = array_values(array_filter($public['actions'], static fn (array $a): bool => in_array($a['name'], $visible, true)));
                $overlays[$slug] = $labels->document([...$public, 'permissions' => $permissions], $locale);
                $menu[$descriptor->toArray()['group'] ?? 'Resources'][] = $slug;
            }

            ksort($menu, SORT_STRING);
            $menu = array_map(static fn (string $group, array $slugs): array => ['label' => $group, 'resources' => $slugs], array_keys($menu), array_values($menu));
            /** @var list<array<string, mixed>> $menu */
            $menu = $labels->document(['menu' => $menu], $locale)['menu'];

            return ['resources' => $overlays, 'menu' => $menu];
        } finally {
            $scope->dispose();
        }
    }

    /**
     * One resource's paths and schemas against its descriptor, as one
     * identity sees both.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $resource the descriptor's resource
     */
    public static function assertDescribes(array $document, string $slug, array $resource): void
    {
        $base = "/vector/r/{$slug}";
        $paths = $document['paths'];
        $schemas = $document['components']['schemas'];
        $fields = array_column($resource['fields'], null, 'name');
        $list = $paths[$base]['get'];
        $parameters = array_column($list['parameters'], null, 'name');

        // filters: exactly each field's published operators
        $expectedFilters = [];

        foreach ($fields as $name => $field) {
            foreach ($field['operators'] as $operator) {
                $expectedFilters[] = "filter[{$name}][{$operator}]";
            }
        }

        if ($resource['softDelete'] !== null) {
            $expectedFilters[] = 'filter[trashed]';
            self::assertSame(['only', 'with', 'without'], $parameters['filter[trashed]']['schema']['enum']);
        }

        $filters = array_values(array_filter(array_keys($parameters), static fn (string $n): bool => str_starts_with($n, 'filter[')));
        self::assertEqualsCanonicalizing($expectedFilters, $filters, "{$slug}: filter operators");

        // sort: the sortable fields, either way
        $sortable = array_keys(array_filter($fields, static fn (array $f): bool => $f['sortable'] === true));
        $sorts = $parameters['sort']['schema']['items']['enum'] ?? [];
        self::assertEqualsCanonicalizing([...$sortable, ...array_map(static fn (string $n): string => "-{$n}", $sortable)], $sorts, "{$slug}: sort");

        self::assertSame($resource['table']['maxPerPage'], $parameters['perPage']['schema']['maximum'], "{$slug}: perPage bound");
        self::assertSame($resource['table']['perPage'], $parameters['perPage']['schema']['default']);
        self::assertSame($resource['table']['groupable'], $parameters['group']['schema']['enum'] ?? [], "{$slug}: group");
        self::assertSame($resource['table']['search'] !== [], isset($parameters['q']), "{$slug}: q");
        self::assertSame($resource['table']['summaries'] !== [], isset($parameters['summaries']), "{$slug}: summaries");
        self::assertArrayHasKey('cursor', $parameters);
        self::assertArrayHasKey('page', $parameters);

        $listable = array_values(array_unique([...$resource['table']['columns'], ...$resource['table']['extra'], ...$resource['show']['fields'], ...$resource['show']['extra']]));
        self::assertEqualsCanonicalizing($listable, $parameters['columns']['schema']['items']['enum'], "{$slug}: columns");

        // operations by permission; actions exactly those published
        self::assertSame($resource['permissions']['create'], isset($paths[$base]['post']), "{$slug}: create");
        self::assertSame($resource['permissions']['create'], isset($schemas["{$slug}.Create"]));
        self::assertSame($resource['permissions']['export'], isset($paths["{$base}/export"]), "{$slug}: export");
        self::assertSame($resource['permissions']['create'], isset($paths["{$base}/import/preview"]), "{$slug}: import preview");
        self::assertSame(['get', 'patch', 'delete'], array_keys($paths["{$base}/{id}"]));
        self::assertSame($resource['softDelete'] !== null, isset($paths["{$base}/{id}/restore"]));

        $actions = array_map(
            static fn (string $p): string => substr($p, strlen("{$base}/actions/")),
            array_values(array_filter(array_map('strval', array_keys($paths)), static fn (string $p): bool => str_starts_with($p, "{$base}/actions/"))),
        );
        self::assertSame(array_column($resource['actions'], 'name'), $actions, "{$slug}: actions");

        // every field the schemas or parameters name is one this identity may see
        $named = [
            ...array_keys((array) $schemas["{$slug}.Record"]['properties']),
            ...array_keys((array) $schemas["{$slug}.Update"]['properties']),
            ...array_keys((array) ($schemas["{$slug}.Create"]['properties'] ?? [])),
        ];
        $known = [...array_keys($fields), '_trashed', '_version', 'version'];
        self::assertSame([], array_values(array_diff($named, $known)), "{$slug}: only fields the descriptor publishes");

        foreach (array_map('strval', array_keys($paths)) as $path) {
            if (preg_match('#^' . preg_quote($base, '#') . '/fields/([^/]+)/options$#', $path, $m) === 1) {
                self::assertArrayHasKey($m[1], $fields);
            }
        }
    }

    /**
     * Calls $visit with every key and value under $node, depth first.
     *
     * @param array<array-key, mixed> $node
     * @param \Closure(string, mixed): void $visit
     */
    public static function collect(array $node, \Closure $visit): void
    {
        foreach ($node as $key => $value) {
            $visit((string) $key, $value);

            if (is_array($value)) {
                self::collect($value, $visit);
            }
        }
    }
}
