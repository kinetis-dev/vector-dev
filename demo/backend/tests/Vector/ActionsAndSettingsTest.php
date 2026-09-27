<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;

/** Actions, bulk selections, saved views, preferences and settings. */
final class ActionsAndSettingsTest extends VectorTestCase
{
    public function testTheDescriptorPublishesActionsAndTheShowPageFiltersThemPerRecord(): void
    {
        $schema = $this->client->get('/vector/schema/products', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($schema);
        self::assertSame(['publish-products', 'archive-products', 'adjust-stock', 'reprice-products', 'catalog-summary'], array_column($schema['actions'], 'name'));
        self::assertArrayNotHasKey('class', $schema['actions'][0]);
        self::assertSame('Change by', $schema['actions'][2]['fields'][0]['label']);
        self::assertSame(['eq', 'ne', 'in', 'nin'], $schema['fields'][4]['operators']);
        self::assertSame(['sku', 'name'], $schema['table']['search']);
        self::assertSame(['priceCents', 'stock'], $schema['table']['summaries']);
        self::assertSame(['field' => 'deletedAt'], $schema['softDelete']);

        $archived = (new Query($this->link()))->table('products')->select('id')->where('status', '=', 'archived')->orderBy('id', 'ASC')->first();
        self::assertIsArray($archived);
        $this->client->get("/vector/r/products/{$archived['id']}", headers: $this->adminHeaders())
            ->assertOk()
            // archive is hidden on an archived product; the standalone one is index-only
            ->assertJsonPath('permissions.actions', ['publish-products', 'adjust-stock', 'reprice-products']);
    }

    public function testActionsRunOnIdsOnAMatchingSelectionAndStandalone(): void
    {
        $link = $this->link();
        $draft = (new Query($link))->table('products')->select('id', 'stock')->where('status', '=', 'draft')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->first();
        self::assertIsArray($draft);
        $id = (int) $draft['id'];

        $this->client->post('/vector/r/products/actions/adjust-stock', body: ['ids' => [$id], 'fields' => ['delta' => 5, 'note' => 'restock']], headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('type', 'message')
            ->assertJsonPath('affected', 1);
        self::assertSame((int) $draft['stock'] + 5, (int) (new Query($link))->table('products')->where('id', '=', $id)->value('stock'));

        // an action that declines flushes nothing and answers 422
        $this->client->post('/vector/r/products/actions/adjust-stock', body: ['ids' => [$id], 'fields' => ['delta' => -100000]], headers: $this->adminHeaders())
            ->assertStatus(422)
            ->assertJsonPath('type', 'danger');
        $stock = (int) (new Query($link))->table('products')->where('id', '=', $id)->value('stock');
        self::assertSame((int) $draft['stock'] + 5, $stock, 'the declined action changed nothing');

        // the dialog is validated like a form
        $this->client->post('/vector/r/products/actions/adjust-stock', body: ['ids' => [$id], 'fields' => ['delta' => 'x', 'bogus' => 1]], headers: $this->adminHeaders())
            ->assertValidationError('delta')
            ->assertValidationError('bogus');
        $this->client->post('/vector/r/products/actions/adjust-stock', body: ['ids' => [$id], 'fields' => []], headers: $this->adminHeaders())
            ->assertValidationError('delta');

        // "all matching": every draft product with this sku prefix — put every one of them back afterwards
        $touched = (new Query($link))->table('products')->select('id', 'stock')->where('status', '=', 'draft')->get(); // a superset of what q matches

        try {
            $this->client->post('/vector/r/products/actions/adjust-stock', body: ['all' => ['filter' => ['status' => 'draft'], 'q' => 'SKU-000'], 'fields' => ['delta' => 1]], headers: $this->adminHeaders())
                ->assertOk()
                ->assertJsonPath('type', 'message');
        } finally {
            foreach ($touched as $row) {
                (new Query($link))->table('products')->where('id', '=', $row['id'])->update(['stock' => (int) $row['stock']]);
            }
        }

        // a standalone download
        $response = $this->client->post('/vector/r/products/actions/catalog-summary', body: [], headers: $this->adminHeaders())->assertOk();
        self::assertStringStartsWith('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith("status,products,stock\r\n", $response->body());

        // an unknown action, a record the action refuses, a selection above the limit
        $this->client->post('/vector/r/products/actions/nope', body: ['ids' => [$id]], headers: $this->adminHeaders())->assertNotFound();
        $archived = (new Query($link))->table('products')->select('id')->where('status', '=', 'archived')->orderBy('id', 'ASC')->first();
        self::assertIsArray($archived);
        $this->client->post('/vector/r/products/actions/archive-products', body: ['ids' => [(int) $archived['id']]], headers: $this->adminHeaders())->assertStatus(403);
        $this->client->post('/vector/r/order-lines/batch', body: ['action' => 'delete', 'all' => ['filter' => []]], headers: $this->adminHeaders())->assertStatus(403);

        $events = $this->vectorQuery()->table('vector_action_events')->where('action', '=', 'action:adjust-stock')->count();
        self::assertGreaterThanOrEqual(2, $events);
    }

    public function testSavedViewsAreValidatedOwnedAndShared(): void
    {
        $created = $this->client->post('/vector/views/products', body: [
            'name' => 'Cheap drafts', 'shared' => true, 'isDefault' => true,
            'state' => ['filter' => ['status' => 'draft', 'priceCents' => ['lt' => '5000']], 'sort' => 'priceCents', 'columns' => 'sku,name,priceCents', 'perPage' => 50],
        ], headers: $this->adminHeaders())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Cheap drafts')
            ->assertJsonPath('data.isDefault', true)
            ->assertJsonPath('data.mine', true)
            ->json();
        self::assertIsArray($created);
        $id = $created['data']['id'];

        $this->client->post('/vector/views/products', body: ['name' => 'Broken', 'state' => ['filter' => ['nope' => '1']]], headers: $this->adminHeaders())->assertStatus(422);
        $this->client->post('/vector/views/products', body: ['name' => '', 'state' => []], headers: $this->adminHeaders())->assertValidationError('name');

        // another admin sees the shared view, cannot change it
        $other = ['Authorization' => 'Bearer ' . $this->tokenFor('42')];
        $list = $this->client->get('/vector/views/products', headers: $other)->assertOk()->json();
        self::assertIsArray($list);
        $mine = array_values(array_filter($list['data'], static fn (array $v): bool => $v['id'] === $id));
        self::assertCount(1, $mine);
        self::assertFalse($mine[0]['mine']);
        self::assertFalse($mine[0]['isDefault'], 'a shared view is default only for its owner');
        $this->client->request('PUT', "/vector/views/products/{$id}", body: ['name' => 'Hijacked', 'state' => []], headers: $other)->assertStatus(403);
        $this->client->delete("/vector/views/products/{$id}", headers: $other)->assertStatus(403);

        $this->client->request('PUT', "/vector/views/products/{$id}", body: ['name' => 'Cheap drafts (all)', 'shared' => false, 'state' => ['filter' => ['status' => 'draft']]], headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.name', 'Cheap drafts (all)')
            ->assertJsonPath('data.shared', false);
        $this->client->get('/vector/views/products', headers: $other)->assertOk()->assertJsonPath('data', []);
        $this->client->delete("/vector/views/products/{$id}", headers: $this->adminHeaders())->assertStatus(204);
        $this->client->delete("/vector/views/products/{$id}", headers: $this->adminHeaders())->assertNotFound();
    }

    public function testPreferencesRoundTripPerUser(): void
    {
        $this->client->request('PUT', '/vector/preferences', body: ['columns.products' => ['sku', 'name'], 'density' => 'compact'], headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.density', 'compact');
        $me = $this->client->get('/vector/me', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($me);
        self::assertSame(['sku', 'name'], $me['preferences']['columns.products']);
        $this->client->get('/vector/preferences', headers: ['Authorization' => 'Bearer ' . $this->tokenFor('42')])->assertOk()->assertJsonPath('data', []);
        $this->client->request('PUT', '/vector/preferences', body: ['density' => null, 'bad key!' => 1], headers: $this->adminHeaders())->assertValidationError('bad key!');
        $this->client->request('PUT', '/vector/preferences', body: ['density' => null], headers: $this->adminHeaders())->assertOk();
        $prefs = $this->client->get('/vector/preferences', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($prefs);
        self::assertArrayNotHasKey('density', $prefs['data']);
    }

    public function testBrandingSettingsAreTypedValidatedAndPublishedWithTheSchema(): void
    {
        $this->client->get('/vector/settings', headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.name', 'branding')
            ->assertJsonPath('data.0.values.title', 'Vector');
        $this->client->get('/vector/schema', headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('settings.0.name', 'branding')
            ->assertJsonPath('branding.primaryColor', '#1F5F8B');

        $this->client->request('PUT', '/vector/settings/branding', body: ['title' => '', 'primaryColor' => 'red', 'theme' => 'neon'], headers: $this->adminHeaders())
            ->assertValidationError('title')
            ->assertValidationError('primaryColor')
            ->assertValidationError('theme');

        $etagBefore = $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->getHeaderLine('ETag');
        $this->client->request('PUT', '/vector/settings/branding', body: ['title' => 'Acme Admin', 'primaryColor' => '#AA0000', 'theme' => 'dark', 'footerText' => 'Staging'], headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.values.title', 'Acme Admin')
            ->assertJsonPath('data.values.theme', 'dark')
            ->assertJsonPath('data.values.logoUrl', null);
        $after = $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->assertJsonPath('branding.title', 'Acme Admin');
        self::assertNotSame($etagBefore, $after->getHeaderLine('ETag'), 'branding is part of the descriptor the client caches');

        $this->client->get('/vector/settings/nope', headers: $this->adminHeaders())->assertNotFound();
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'branding')->delete();
    }

    private function link(): MysqlLink
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return $link;
    }
}
