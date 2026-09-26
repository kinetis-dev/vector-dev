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
            ['agents', 'categories', 'customer-profiles', 'customers', 'labels', 'order-lines', 'orders', 'products', 'support-teams', 'tags', 'ticket-replies', 'tickets', 'users'],
            array_keys($document['resources']),
        );
        self::assertSame(['Access', 'Catalog', 'Sales', 'Support'], array_column($document['menu'], 'label'));

        foreach ($document['resources']['products']['fields'] as $field) {
            self::assertArrayNotHasKey('column', $field, 'the published descriptor names no database column');
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
