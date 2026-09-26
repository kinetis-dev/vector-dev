<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Support\Ticket;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\QueryBuilder\Query;

/**
 * The support desk lives on the PostgreSQL connection "support" while the
 * shop lives on MySQL: every Vector feature over it — reads, filters,
 * search, summaries, grouping, cursor pages, relations, writes, unique
 * and version conflicts, soft deletion, actions, export, audit, global
 * search, MCP — runs on the entity's own connection and dialect.
 */
final class SupportDomainTest extends VectorTestCase
{
    public function testTheCompiledRegistryKnowsEachResourcesConnectionAndDoesNotPublishIt(): void
    {
        $registry = $this->app->get(\Kinetis\Vector\Registry\VectorRegistry::class);
        self::assertInstanceOf(\Kinetis\Vector\Registry\VectorRegistry::class, $registry);
        self::assertSame('support', $registry->resource('tickets')->connection());
        self::assertSame('support', $registry->resource('agents')->connection());
        self::assertSame('default', $registry->resource('products')->connection());

        $schema = $this->client->get('/vector/schema/tickets', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($schema);
        self::assertArrayNotHasKey('connection', $schema);
        self::assertSame(['eq', 'ne', 'in', 'nin', 'null', 'nnull'], $schema['fields'][10]['operators'], 'a nullable belongsTo admits null');
    }

    public function testListsFilterSearchAndAggregateOnPostgres(): void
    {
        $h = $this->adminHeaders();
        $total = $this->support()->table('tickets')->where('deleted_at', '=', null)->count();
        $this->client->get('/vector/r/tickets', query: ['perPage' => 5], headers: $h)->assertOk()->assertJsonPath('total', $total);

        // search and the contains filter ignore case on PostgreSQL too
        $lower = $this->client->get('/vector/r/tickets', query: ['q' => 'refund', 'perPage' => 200], headers: $h)->assertOk()->json();
        $upper = $this->client->get('/vector/r/tickets', query: ['q' => 'REFUND', 'perPage' => 200], headers: $h)->assertOk()->json();
        self::assertIsArray($lower);
        self::assertIsArray($upper);
        self::assertGreaterThan(0, $lower['total']);
        self::assertSame($lower['total'], $upper['total']);
        $like = $this->client->get('/vector/r/tickets', query: ['filter' => ['subject' => ['like' => 'where is']], 'perPage' => 200], headers: $h)->assertOk()->json();
        self::assertIsArray($like);
        self::assertGreaterThan(0, $like['total']);
        self::assertSame([], array_filter($like['data'], static fn (array $t): bool => !str_contains(strtolower($t['subject']), 'where is')));
        $nlike = $this->client->get('/vector/r/tickets', query: ['filter' => ['subject' => ['nlike' => 'WHERE IS']], 'perPage' => 1], headers: $h)->assertOk()->json();
        self::assertIsArray($nlike);
        self::assertSame($total, $like['total'] + $nlike['total']);

        // typed filters bind PostgreSQL's own types: boolean, enum lists, null, instants
        $escalated = $this->client->get('/vector/r/tickets', query: ['filter' => ['isEscalated' => 'true'], 'perPage' => 200], headers: $h)->assertOk()->json();
        self::assertIsArray($escalated);
        self::assertSame([true], array_values(array_unique(array_column($escalated['data'], 'isEscalated'))));
        $open = $this->client->get('/vector/r/tickets', query: ['filter' => ['status' => ['in' => 'open,pending'], 'assignee' => ['null' => '']], 'perPage' => 200], headers: $h)->assertOk()->json();
        self::assertIsArray($open);

        foreach ($open['data'] as $ticket) {
            self::assertContains($ticket['status'], ['open', 'pending']);
            self::assertNull($ticket['assignee']);
        }

        $this->client->get('/vector/r/tickets', query: ['filter' => ['dueAt' => ['lte' => '2026-01-01']], 'perPage' => 1], headers: $h)->assertOk();

        // grouping, summaries, cursor pages
        $grouped = $this->client->get('/vector/r/tickets', query: ['group' => 'status', 'perPage' => 200, 'summaries' => '1'], headers: $h)->assertOk()->json();
        self::assertIsArray($grouped);
        $statuses = array_column($grouped['data'], 'status');
        $sorted = $statuses;
        sort($sorted);
        self::assertSame($sorted, $statuses);
        self::assertIsInt($grouped['summaries']['compensationCents']);
        self::assertTrue($grouped['summaries']['satisfaction'] === null || is_float($grouped['summaries']['satisfaction']));
        $first = $this->client->get('/vector/r/tickets', query: ['cursor' => '', 'perPage' => 3], headers: $h)->assertOk()->json();
        self::assertIsArray($first);
        $second = $this->client->get('/vector/r/tickets', query: ['cursor' => $first['nextCursor'], 'perPage' => 3], headers: $h)->assertOk()->json();
        self::assertIsArray($second);
        self::assertGreaterThan($first['data'][2]['id'], $second['data'][0]['id']);
    }

    public function testRelationsOptionsAndRelatedListsStayOnTheirConnection(): void
    {
        $h = $this->adminHeaders();
        $ticket = $this->support()->table('ticket_replies')->select('ticket_id')->orderBy('ticket_id', 'ASC')->first();
        self::assertIsArray($ticket);
        $id = (int) $ticket['ticket_id'];
        $replies = $this->support()->table('ticket_replies')->where('ticket_id', '=', $id)->count();

        $show = $this->client->get("/vector/r/tickets/{$id}", headers: $h)->assertOk()->json();
        self::assertIsArray($show);
        self::assertSame($replies, $show['data']['replies']['count']);
        self::assertSame(['id', 'label'], array_keys($show['data']['team']));
        $this->client->get("/vector/r/tickets/{$id}/replies", headers: $h)->assertOk()->assertJsonPath('total', $replies);

        $options = $this->client->get('/vector/r/tickets/fields/assignee/options', query: ['q' => 'MI'], headers: $h)->assertOk()->json();
        self::assertIsArray($options);
        self::assertNotEmpty($options['data'], 'relation search ignores case on PostgreSQL');
        $team = $this->client->get('/vector/r/support-teams', headers: $h)->assertOk()->json();
        self::assertIsArray($team);
        self::assertArrayHasKey('count', $team['data'][0]['agents']);
    }

    public function testWritesConflictsSoftDeletionAndTheAuditRunOnTheSupportConnection(): void
    {
        $h = $this->adminHeaders();
        $teamId = (int) $this->support()->table('support_teams')->select('id')->orderBy('id', 'ASC')->value('id');
        $agentId = (int) $this->support()->table('agents')->select('id')->orderBy('id', 'ASC')->value('id');
        $labelIds = array_map('intval', array_column($this->support()->table('labels')->select('id')->orderBy('id', 'ASC')->limit(2)->get(), 'id'));
        $number = 'TCK-T' . substr(bin2hex(random_bytes(4)), 0, 7);

        $created = $this->client->post('/vector/r/tickets', body: [
            'number' => $number, 'subject' => 'Parcel lost', 'body' => 'It never arrived.', 'status' => 'open', 'priority' => 'high',
            'channel' => 'email', 'customerEmail' => 'lost@example.com', 'team' => $teamId, 'assignee' => $agentId, 'labels' => $labelIds,
            'compensationCents' => 1500, 'currency' => 'EUR', 'isEscalated' => true, 'metadata' => '{"carrier": "DHL"}',
            'dueAt' => '2026-10-01T09:00:00Z',
        ], headers: $h)
            ->assertCreated()
            ->assertJsonPath('data.number', $number)
            ->assertJsonPath('data.team.id', $teamId)
            ->assertJsonPath('data.assignee.id', $agentId)
            ->assertJsonPath('data.labels.count', 2)
            ->assertJsonPath('data.isEscalated', true)
            ->assertJsonPath('data.compensationCents', 1500)
            ->json();
        self::assertIsArray($created);
        $id = $created['data']['id'];
        self::assertSame(1, $this->support()->table('tickets')->where('number', '=', $number)->count(), 'the row is in the PostgreSQL database');

        // PostgreSQL's unique violation lands on the field it names
        $this->client->post('/vector/r/tickets', body: [
            'number' => $number, 'subject' => 'Dup', 'body' => 'x', 'status' => 'open', 'priority' => 'low', 'channel' => 'web',
            'customerEmail' => 'dup@example.com', 'team' => $teamId, 'compensationCents' => 0, 'currency' => 'EUR', 'isEscalated' => false,
        ], headers: $h)->assertValidationError('number');

        // the version column and If-Match
        $this->client->request('PATCH', "/vector/r/tickets/{$id}", body: ['priority' => 'urgent', 'assignee' => null, 'labels' => [$labelIds[0]]], headers: [...$h, 'If-Match' => '"1"'])
            ->assertOk()
            ->assertJsonPath('data.priority', 'urgent')
            ->assertJsonPath('data.assignee', null)
            ->assertJsonPath('data.labels.count', 1);
        $this->client->request('PATCH', "/vector/r/tickets/{$id}", body: ['subject' => 'Stale'], headers: [...$h, 'If-Match' => '"1"'])->assertStatus(409);

        // a related record from another connection's resource is refused like any unknown id
        $this->client->request('PATCH', "/vector/r/tickets/{$id}", body: ['team' => 999999], headers: $h)->assertValidationError('team');

        // the audit trail and the action log live on Vector's connection
        $trail = $this->client->get("/vector/r/tickets/{$id}/audit", headers: $h)->assertOk()->json();
        self::assertIsArray($trail);
        self::assertSame('update', $trail['data'][0]['event']);
        self::assertSame('high', $trail['data'][0]['changes']['priority']['from']);

        // soft deletion on PostgreSQL, then for good
        $this->client->delete("/vector/r/tickets/{$id}", headers: $h)->assertStatus(204);
        $this->client->get("/vector/r/tickets/{$id}", headers: $h)->assertOk()->assertJsonPath('data._trashed', true);
        $this->client->get('/vector/r/tickets', query: ['filter' => ['number' => $number, 'trashed' => 'only']], headers: $h)->assertOk()->assertJsonPath('total', 1);
        $this->client->post("/vector/r/tickets/{$id}/restore", headers: $h)->assertOk()->assertJsonPath('data._trashed', false);
        $this->client->request('DELETE', "/vector/r/tickets/{$id}", headers: $h, query: ['force' => '1'])->assertStatus(204);
        self::assertSame(0, $this->support()->table('tickets')->where('number', '=', $number)->count());
        self::assertSame(0, $this->support()->table('ticket_label')->where('ticket_id', '=', $id)->count());
    }

    public function testActionsExportGlobalSearchAndMcpOnTheSupportConnection(): void
    {
        $h = $this->adminHeaders();
        $open = $this->support()->table('tickets')->select('id')->where('status', '=', 'open')->where('is_escalated', '=', false)->where('deleted_at', '=', null)->orderBy('id', 'ASC')->limit(2)->get();
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $open);
        self::assertCount(2, $ids);

        $this->client->post('/vector/r/tickets/actions/escalate-tickets', body: ['ids' => $ids], headers: $h)->assertOk()->assertJsonPath('affected', 2);
        self::assertSame(2, $this->support()->table('tickets')->whereIn('id', $ids)->where('is_escalated', '=', true)->where('priority', '=', 'urgent')->count());
        $this->support()->table('tickets')->whereIn('id', $ids)->update(['is_escalated' => false, 'priority' => 'normal']);

        $csv = $this->client->get('/vector/r/tickets/export', query: ['filter' => ['status' => 'closed'], 'columns' => 'number,subject,team,status'], headers: $h)->assertOk()->body();
        self::assertStringStartsWith("\xEF\xBB\xBFNumber,Subject,Team,Status\r\n", $csv);

        $results = $this->client->get('/vector/search', query: ['q' => 'tck-0000'], headers: $h)->assertOk()->json();
        self::assertIsArray($results);
        self::assertContains('tickets', array_column($results['results'], 'resource'));

        $body = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'vector_query', 'arguments' => ['resource' => 'tickets', 'filter' => ['priority' => ['in' => 'urgent,high']], 'perPage' => 3]]], JSON_THROW_ON_ERROR);
        $call = $this->client->raw('POST', '/mcp', $body, [...$h, 'MCP-Protocol-Version' => '2025-06-18', 'Content-Type' => 'application/json'])->assertOk()->json();
        self::assertIsArray($call);
        self::assertFalse($call['result']['isError'] ?? false);
        $page = json_decode($call['result']['content'][0]['text'], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($page);
        self::assertSame([], array_diff(array_column($page['data'], 'priority'), ['urgent', 'high']));
    }

    public function testADateOnlyPropertyIsACalendarDayEndToEnd(): void
    {
        $h = $this->adminHeaders();
        $schema = $this->client->get('/vector/schema/agents', headers: $h)->assertOk()->json();
        self::assertIsArray($schema);
        $joined = array_values(array_filter($schema['fields'], static fn (array $f): bool => $f['name'] === 'joinedOn'))[0];
        self::assertSame(['date', 'date'], [$joined['type'], $joined['valueType']], 'a Kinetis\\Orm\\Date property is a date value, not an instant');

        $teamId = (int) $this->support()->table('support_teams')->select('id')->orderBy('id', 'ASC')->value('id');
        $tag = bin2hex(random_bytes(4));
        $email = "leap.{$tag}@vector.local";
        $body = ['name' => "Leap {$tag}", 'email' => $email, 'team' => $teamId, 'role' => 'agent', 'isActive' => true];

        try {
            $id = $this->client->post('/vector/r/agents', body: [...$body, 'joinedOn' => '2024-02-29'], headers: $h)
                ->assertCreated()
                ->assertJsonPath('data.joinedOn', '2024-02-29')
                ->json();
            self::assertIsArray($id);
            $id = $id['data']['id'];
            self::assertIsInt($id);
            self::assertSame('2024-02-29', $this->support()->table('agents')->select('joined_on')->where('id', '=', $id)->value('joined_on'));

            // no time and no zone: the day read back is the day written
            $this->client->get("/vector/r/agents/{$id}", headers: $h)->assertOk()->assertJsonPath('data.joinedOn', '2024-02-29');

            // filters bind the day itself
            foreach ([['eq' => '2024-02-29'], ['between' => '2024-02-29,2024-02-29'], ['gte' => '2024-02-29', 'lte' => '2024-02-29']] as $filter) {
                $found = $this->client->get('/vector/r/agents', query: ['filter' => ['joinedOn' => $filter, 'name' => ['like' => $tag]], 'perPage' => 5], headers: $h)->assertOk()->json();
                self::assertIsArray($found);
                self::assertSame([$id], array_column($found['data'], 'id'), json_encode($filter, JSON_THROW_ON_ERROR));
            }

            $this->client->get('/vector/r/agents', query: ['filter' => ['joinedOn' => ['eq' => '2024-02-30']]], headers: $h)->assertStatus(422);
            $this->client->get('/vector/r/agents', query: ['filter' => ['joinedOn' => ['gt' => 'yesterday']]], headers: $h)->assertStatus(422);

            // a day that does not exist, and an instant, are refused on write
            $this->client->patch("/vector/r/agents/{$id}", body: ['joinedOn' => '2023-02-29'], headers: $h)->assertStatus(422);
            $this->client->patch("/vector/r/agents/{$id}", body: ['joinedOn' => '2024-03-01T10:00:00Z'], headers: $h)->assertStatus(422);
            $this->client->patch("/vector/r/agents/{$id}", body: ['joinedOn' => '2024-03-01'], headers: $h)->assertOk()->assertJsonPath('data.joinedOn', '2024-03-01');
            $this->client->patch("/vector/r/agents/{$id}", body: ['joinedOn' => null], headers: $h)->assertOk()->assertJsonPath('data.joinedOn', null);
        } finally {
            $this->support()->table('agents')->where('email', '=', $email)->delete();
        }
    }

    public function testEachConnectionGetsItsOwnManagerInOneRequestScope(): void
    {
        $scope = $this->app->createRequestScope();

        try {
            $managers = $scope->get(EntityManagerRegistry::class);
            self::assertInstanceOf(EntityManagerRegistry::class, $managers);
            self::assertNotSame($managers->manager('default'), $managers->managerFor(Ticket::class));
            self::assertSame($managers->manager('support'), $managers->managerFor(Ticket::class));
        } finally {
            $scope->dispose();
        }
    }

    private ?EntityManager $supportManager = null;

    /** A builder on the support database, through a manager this test opens and closes. */
    private function support(): Query
    {
        if ($this->supportManager === null) {
            $factories = $this->app->get(OrmFactoryRegistry::class);
            self::assertInstanceOf(OrmFactoryRegistry::class, $factories);
            $this->supportManager = $factories->factory('support')->open();
        }

        return $this->supportManager->builder();
    }

    protected function tearDown(): void
    {
        $this->supportManager?->close();
        $this->supportManager = null;
        parent::tearDown();
    }
}
