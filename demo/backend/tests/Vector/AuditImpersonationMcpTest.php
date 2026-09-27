<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;

/** The audit trail, impersonation in bearer mode, and the MCP tools over HTTP. */
final class AuditImpersonationMcpTest extends VectorTestCase
{
    public function testTheAuditTrailRecordsFieldLevelChanges(): void
    {
        $link = $this->link();
        $product = (new Query($link))->table('products')->select('id', 'stock', 'name')->where('deleted_at', '=', null)->orderBy('id', 'DESC')->first();
        self::assertIsArray($product);
        $id = (int) $product['id'];

        $this->client->request('PATCH', "/vector/r/products/{$id}", body: ['stock' => (int) $product['stock'] + 7], headers: $this->adminHeaders())->assertOk();
        $trail = $this->client->get("/vector/r/products/{$id}/audit", headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($trail);
        $latest = $trail['data'][0];
        self::assertSame('update', $latest['event']);
        self::assertSame('1', $latest['actorId']);
        self::assertNull($latest['impersonatorId']);
        self::assertSame(['stock', 'updatedAt'], array_keys($latest['changes']));
        self::assertSame((int) $product['stock'], $latest['changes']['stock']['from']);
        self::assertSame((int) $product['stock'] + 7, $latest['changes']['stock']['to']);

        // the trail is the newest 100 entries, so a new one is told by its id, not by the count
        $this->client->request('PATCH', "/vector/r/products/{$id}", body: ['name' => $product['name']], headers: $this->adminHeaders())->assertOk();
        $again = $this->client->get("/vector/r/products/{$id}/audit", headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($again);
        self::assertGreaterThan($latest['id'], $again['data'][0]['id'], 'only updatedAt changed, which the writer stamps; that is a change too');
        self::assertSame(['updatedAt'], array_keys($again['data'][0]['changes']));

        (new Query($link))->table('products')->where('id', '=', $id)->update(['stock' => (int) $product['stock']]);
        $this->client->get('/vector/r/products/999999/audit', headers: $this->adminHeaders())->assertNotFound();
    }

    public function testImpersonationSwapsTheBearerIdentityAndIsLogged(): void
    {
        $link = $this->link();
        $editor = (new Query($link))->table('users')->select('id', 'name')->where('email', '=', 'editor@vector.local')->first();
        self::assertIsArray($editor);
        $admin = (new Query($link))->table('users')->select('id')->where('email', '=', 'admin@vector.local')->first();
        self::assertIsArray($admin);
        $adminHeaders = ['Authorization' => 'Bearer ' . $this->tokenFor((string) $admin['id'])];

        $this->client->get('/vector/schema', headers: $adminHeaders)->assertOk()->assertJsonPath('capabilities.impersonation', true);

        $started = $this->client->post("/vector/impersonate/{$editor['id']}", headers: $adminHeaders)
            ->assertOk()
            ->assertJsonPath('target.name', $editor['name'])
            ->json();
        self::assertIsArray($started);
        $asEditor = ['Authorization' => 'Bearer ' . $started['accessToken']];

        // the impersonated editor is not an admin: the admin is refused too, exactly as the editor would be —
        // except for stopping, which must always be reachable
        $this->client->get('/vector/schema', headers: $asEditor)->assertStatus(403);
        $back = $this->client->delete('/vector/impersonate', headers: $asEditor)->assertOk()->json();
        self::assertIsArray($back);
        $this->client->get('/vector/schema', headers: ['Authorization' => 'Bearer ' . $back['accessToken']])->assertOk();

        $events = $this->vectorQuery()->table('vector_action_events')->where('action', '=', 'impersonate:start')->orderBy('id', 'DESC')->first();
        self::assertIsArray($events);
        self::assertSame((string) $admin['id'], $events['actor_id']);

        // an admin impersonating another admin keeps access, and the impersonator rides on every write
        $second = (int) (new Query($link))->table('users')->insertGetId(['email' => 'second-admin-' . bin2hex(random_bytes(3)) . '@vector.local', 'name' => 'Second Admin', 'password_hash' => 'x', 'is_admin' => 1, 'created_at' => '2026-09-25 00:00:00']);

        try {
            $this->impersonatesASecondAdmin($link, $second, $admin, $editor, $adminHeaders);
        } finally {
            (new Query($link))->table('users')->where('id', '=', $second)->delete();
        }
    }

    /**
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $editor
     * @param array<string, string> $adminHeaders
     */
    private function impersonatesASecondAdmin(MysqlLink $link, int $second, array $admin, array $editor, array $adminHeaders): void
    {
        $started = $this->client->post("/vector/impersonate/{$second}", headers: $adminHeaders)->assertOk()->json();
        self::assertIsArray($started);
        $asSecond = ['Authorization' => 'Bearer ' . $started['accessToken']];
        $this->client->get('/vector/me', headers: $asSecond)->assertOk()->assertJsonPath('id', $second)->assertJsonPath('impersonator', (int) $admin['id']);
        $this->client->post("/vector/impersonate/{$editor['id']}", headers: $asSecond)->assertStatus(403);

        $tag = $this->client->post('/vector/r/tags', body: ['name' => 'impersonated-' . bin2hex(random_bytes(2)), 'color' => '#000000'], headers: $asSecond)->assertCreated()->json();
        self::assertIsArray($tag);
        $audit = $this->vectorQuery()->table('vector_audit')->where('resource', '=', 'tags')->where('record_id', '=', (string) $tag['data']['id'])->first();
        self::assertIsArray($audit);
        self::assertSame((string) $second, $audit['actor_id']);
        self::assertSame((string) $admin['id'], $audit['impersonator_id']);

        $stopped = $this->client->delete('/vector/impersonate', headers: $asSecond)->assertOk()->json();
        self::assertIsArray($stopped);
        $this->client->get('/vector/me', headers: ['Authorization' => 'Bearer ' . $stopped['accessToken']])->assertOk()->assertJsonPath('id', (int) $admin['id'])->assertJsonPath('impersonator', null);
        $this->client->delete('/vector/impersonate', headers: $adminHeaders)->assertStatus(403);

        $this->client->delete("/vector/r/tags/{$tag['data']['id']}", headers: $adminHeaders)->assertStatus(204);
    }

    public function testTheMcpToolsAnswerWithTheSamePoliciesAsTheRoutes(): void
    {
        $headers = [...$this->adminHeaders(), 'MCP-Protocol-Version' => '2025-06-18', 'Content-Type' => 'application/json'];
        $list = $this->client->raw('POST', '/mcp', json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR), $headers)->assertOk()->json();
        self::assertIsArray($list);
        $names = array_column($list['result']['tools'], 'name');

        foreach (['vector_resources', 'vector_query', 'vector_get', 'vector_create', 'vector_update', 'vector_delete', 'vector_run_action', 'vector_widget'] as $tool) {
            self::assertContains($tool, $names);
        }

        // open-shaped arguments are plain JSON objects in the published schema
        $schemas = array_column($list['result']['tools'], 'inputSchema', 'name');
        self::assertSame('object', $schemas['vector_query']['properties']['filter']['type']);
        self::assertSame('object', $schemas['vector_create']['properties']['values']['type']);
        self::assertSame('object', $schemas['vector_update']['properties']['values']['type']);
        self::assertSame('object', $schemas['vector_run_action']['properties']['values']['type']);

        $query = $this->call($headers, 'vector_query', ['resource' => 'products', 'filter' => ['status' => 'archived'], 'sort' => '-priceCents', 'perPage' => 3]);
        self::assertFalse($query['isError'] ?? false, $query['content'][0]['text'] ?? '');
        $page = json_decode($query['content'][0]['text'], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($page);
        self::assertCount(3, $page['data']);
        self::assertSame(['archived'], array_values(array_unique(array_column($page['data'], 'status'))));

        // a queued action rebuilds its admin in the job, so it runs as a real user
        $realHeaders = [...$this->realAdminHeaders(), 'MCP-Protocol-Version' => '2025-06-18', 'Content-Type' => 'application/json'];
        $action = $this->call($realHeaders, 'vector_run_action', ['resource' => 'products', 'action' => 'reprice-products', 'ids' => array_map('strval', array_column($page['data'], 'id')), 'values' => ['percent' => 0]]);
        self::assertFalse($action['isError'] ?? false, $action['content'][0]['text'] ?? '');
        self::assertSame('job', json_decode($action['content'][0]['text'], true, 16, JSON_THROW_ON_ERROR)['type'] ?? null, 'the dialog values reached the queued action');
        $missing = $this->call($realHeaders, 'vector_run_action', ['resource' => 'products', 'action' => 'reprice-products', 'ids' => array_map('strval', array_column($page['data'], 'id'))]);
        self::assertTrue($missing['isError'], 'without values the dialog is incomplete');

        $widget = $this->call($headers, 'vector_widget', ['widget' => 'orders-by-status', 'range' => '30d']);
        self::assertFalse($widget['isError'] ?? false);

        $bad = $this->call($headers, 'vector_query', ['resource' => 'products', 'filter' => ['bogus' => '1']]);
        self::assertTrue($bad['isError']);
        self::assertStringContainsString('not a filterable field', $bad['content'][0]['text']);

        $created = $this->call($headers, 'vector_create', ['resource' => 'tags', 'values' => ['name' => 'mcp-' . bin2hex(random_bytes(2)), 'color' => '#101010']]);
        self::assertFalse($created['isError'] ?? false, $created['content'][0]['text'] ?? '');
        $tag = json_decode($created['content'][0]['text'], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($tag);
        $invalid = $this->call($headers, 'vector_update', ['resource' => 'tags', 'id' => (string) $tag['data']['id'], 'values' => ['color' => 'not-a-colour', 'bogus' => 1]]);
        self::assertTrue($invalid['isError']);
        self::assertStringContainsString('bogus', $invalid['content'][0]['text']);
        $list = $this->call($headers, 'vector_create', ['resource' => 'tags', 'values' => ['a', 'b']]);
        self::assertTrue($list['isError'], 'a JSON array is not an object of values');
        $deleted = $this->call($headers, 'vector_delete', ['resource' => 'tags', 'id' => (string) $tag['data']['id']]);
        self::assertFalse($deleted['isError'] ?? false);

        $denied = $this->call([...$this->editorHeaders(), 'MCP-Protocol-Version' => '2025-06-18', 'Content-Type' => 'application/json'], 'vector_resources', []);
        self::assertTrue($denied['isError']);
        self::assertStringContainsString('no access', $denied['content'][0]['text']);

        $this->client->raw('POST', '/mcp', json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR), ['MCP-Protocol-Version' => '2025-06-18', 'Content-Type' => 'application/json'])->assertStatus(401);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(array $headers, string $tool, array $arguments): array
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]], JSON_THROW_ON_ERROR);
        $response = $this->client->raw('POST', '/mcp', $body, $headers)->assertOk()->json();
        self::assertIsArray($response);
        self::assertArrayHasKey('result', $response, json_encode($response, JSON_THROW_ON_ERROR));

        return $response['result'];
    }

    private function link(): MysqlLink
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return $link;
    }
}
