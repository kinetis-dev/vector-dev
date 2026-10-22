<?php

declare(strict_types=1);

namespace App\Tests\Vector;

final class SchemaControllerTest extends VectorTestCase
{
    public function testTheDescriptorListsEveryDemoResourceForAnAdmin(): void
    {
        $response = $this->client->get('/vector/schema', headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('version', 4)
            ->assertJsonPath('resources.products.slug', 'products')
            ->assertJsonPath('resources.products.titleField', 'name')
            ->assertJsonPath('resources.products.permissions.viewAny', true)
            ->assertJsonPath('resources.products.table.defaultSort.0.field', 'createdAt')
            ->assertJsonPath('resources.customers.fields.8.type', 'hasOne')
            ->assertJsonPath('resources.customers.fields.9.relation.resource', 'orders');

        $document = $response->json();
        self::assertIsArray($document);
        self::assertSame(
            ['agents', 'categories', 'customer-profiles', 'customers', 'labels', 'order-lines', 'orders', 'products', 'profiles', 'support-teams', 'tags', 'ticket-replies', 'tickets', 'users'],
            array_keys($document['resources']),
        );
        self::assertSame(['Access', 'Catalog', 'Sales', 'Support'], array_column($document['menu'], 'label'));

        foreach ($document['resources']['products']['fields'] as $field) {
            self::assertArrayNotHasKey('column', $field, 'the published descriptor names no database column');
        }

        // nor the primary key's: a client addresses records by the key's field
        foreach ($document['resources'] as $slug => $resource) {
            self::assertSame(['field', 'type'], array_keys($resource['primaryKey']), $slug);
        }
    }

    public function testThePublishedDescriptorValidatesAgainstItsJsonSchema(): void
    {
        $document = $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->body();
        $schema = file_get_contents(dirname(__DIR__, 4) . '/packages/vector/resources/schema/descriptor.schema.json');
        self::assertIsString($schema);

        $validator = new \Opis\JsonSchema\Validator();
        $result = $validator->validate(json_decode($document, false, 64, JSON_THROW_ON_ERROR), $schema);

        self::assertTrue($result->isValid(), $result->error() === null ? '' : (new \Opis\JsonSchema\Errors\ErrorFormatter())->formatOutput($result->error(), 'basic')['errors'][0]['error'] ?? 'invalid');
    }

    /**
     * Validity alone lets a key the schema never names slip through: every
     * key the demo's descriptor publishes — at the top, on each resource,
     * field, action and widget, and in the capabilities — is declared.
     */
    public function testTheJsonSchemaNamesEveryKeyTheDescriptorPublishes(): void
    {
        $document = $this->client->get('/vector/schema', headers: $this->headersIn('he'))->assertOk()->json();
        self::assertIsArray($document);
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/packages/vector/resources/schema/descriptor.schema.json'), true, 64, JSON_THROW_ON_ERROR);
        $declared = static fn (array $definition): array => array_keys($definition['properties'] ?? []);
        $undeclared = [];

        $check = static function (string $where, array $published, array $known) use (&$undeclared): void {
            foreach (array_diff(array_keys($published), $known) as $key) {
                $undeclared["{$where}.{$key}"] = true;
            }
        };

        $check('descriptor', $document, $declared($schema));
        $check('capabilities', $document['capabilities'], $declared($schema['properties']['capabilities']));
        $check('i18n', $document['i18n'], $declared($schema['properties']['i18n']));

        foreach ($document['resources'] as $slug => $resource) {
            $check("resources.{$slug}", $resource, $declared($schema['$defs']['resource']));

            // and one level down, in the objects the schema spells out
            foreach ($schema['$defs']['resource']['properties'] as $key => $definition) {
                if (isset($definition['properties']) && is_array($resource[$key] ?? null)) {
                    $check("resources.{$slug}.{$key}", $resource[$key], $declared($definition));
                }
            }

            foreach ($resource['fields'] as $field) {
                $check("resources.{$slug}.fields", $field, $declared($schema['$defs']['field']));

                foreach ($schema['$defs']['field']['properties'] as $key => $definition) {
                    if (isset($definition['properties']) && is_array($field[$key] ?? null)) {
                        $check("resources.{$slug}.fields.{$key}", $field[$key], $declared($definition));
                    }
                }
            }

            foreach ($resource['actions'] as $action) {
                $check("resources.{$slug}.actions", $action, $declared($schema['$defs']['action']));
            }

            foreach ($resource['widgets'] as $widget) {
                $check("resources.{$slug}.widgets", $widget, $declared($schema['$defs']['widget']));
            }
        }

        self::assertSame([], array_keys($undeclared));
    }

    public function testTheDescriptorIsCachedPerIdentity(): void
    {
        $etag = $this->client->get('/vector/schema', headers: $this->adminHeaders())
            ->assertOk()
            ->assertHeader('ETag')
            ->getHeaderLine('ETag');

        $this->client->get('/vector/schema', headers: [...$this->adminHeaders(), 'If-None-Match' => $etag])
            ->assertStatus(304);
    }

    public function testASingleResourceIsPublished(): void
    {
        $this->client->get('/vector/schema/products', headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('slug', 'products')
            ->assertJsonPath('fields.6.type', 'money');

        $this->client->get('/vector/schema/nope', headers: $this->adminHeaders())
            ->assertNotFound();
    }

    public function testTheGroupRefusesTheUnauthenticatedAndTheUnadmitted(): void
    {
        $this->client->get('/vector/schema')->assertStatus(401);
        $this->client->get('/vector/schema', headers: $this->editorHeaders())->assertStatus(403);
        $this->client->get('/vector/me', headers: $this->adminHeaders())->assertOk()->assertJsonPath('id', 1);
    }
}
