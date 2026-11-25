<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Auth\DemoActor;
use App\Domain\Product;
use App\Domain\Support\Ticket;
use App\Domain\Support\TicketReply;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Database\JsonDepth;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Json;
use Kinetis\Vector\Fields\Repeater;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Testing\InteractsWithVector;
use Kinetis\Vector\Testing\VectorResponse;
use Kinetis\Vector\VectorResource;
use Kinetis\Vector\Write\RecordWriter;
use stdClass;

/**
 * A JSON or key-value field on a property that keeps JSON objects
 * (`stdClass`, `array|stdClass`): a REST create or update stores an object
 * as an object, an empty or nested one included, and a list as a list, and
 * the API answers it the same way. The ticket's metadata (`array|stdClass|null`)
 * lives on PostgreSQL, the product's attributes (`?stdClass`) on MySQL.
 *
 * The test's own registry adds two resources with no timestamps, so a write
 * that changes nothing has nothing to stamp, and marks the product's
 * attributes as not nullable, so an empty one is stored rather than cleared.
 * An `array` property is refused for either field (JsonColumnTypesTest).
 */
final class JsonObjectFieldTest extends VectorTestCase
{
    use InteractsWithVector;

    private ?EntityManager $supportManager = null;

    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $registry = $app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $data = $registry->toArray();
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));

        // each on its own: two resources over one entity in one compile would ask which one relations link to
        foreach ([[ObjectTickets::class], [RequiredObjectTickets::class], [RepliedTickets::class, ObjectReplies::class]] as $classes) {
            $data['resources'] += (new ResourceCompiler($metadata, $classes))->compile();
        }

        foreach ($data['resources']['products']['fields'] as $i => $field) {
            if (is_array($field) && ($field['name'] ?? null) === 'attributes') {
                $data['resources']['products']['fields'][$i]['nullable'] = false;
            }
        }

        $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));
    }

    public function testAJsonDocumentKeepsItsObjectsOnPostgres(): void
    {
        [$id, $stored] = $this->ticket();
        $tickets = $this->vector($this->adminHeaders())->resource('tickets');

        try {
            foreach ([
                'an empty object' => [new stdClass(), '{}'],
                'a nested empty object' => [(object) ['box' => new stdClass()], '{"box":{}}'],
                'an object' => [(object) ['carrier' => 'DHL', 'parcels' => [1, 2], 'insured' => false], '{"carrier":"DHL","insured":false,"parcels":[1,2]}'],
                'a list' => [[1, new stdClass(), []], '[1,{},[]]'],
            ] as $case => [$sent, $json]) {
                $answer = self::objects($tickets->update($id, ['metadata' => $sent])->assertOk());
                self::assertEquals(json_decode($json, false), $answer->data->metadata, "{$case}: answered");
                self::assertSame(self::canonical($json), self::canonical(json_encode($answer->data->metadata, JSON_THROW_ON_ERROR)), "{$case}: answered with its shape");
                self::assertSame(self::canonical($json), $this->storedMetadata($id), "{$case}: stored");
                self::assertSame(self::canonical($json), self::canonical(json_encode(self::objects($tickets->show($id)->assertOk())->data->metadata, JSON_THROW_ON_ERROR)), "{$case}: read back");
            }
        } finally {
            $this->support()->table('tickets')->where('id', '=', $id)->update(['metadata' => $stored]);
        }
    }

    public function testACreatedTicketKeepsANestedEmptyObject(): void
    {
        $teamId = (int) $this->support()->table('support_teams')->select('id')->orderBy('id', 'ASC')->value('id');
        $number = 'TCK-J' . substr(bin2hex(random_bytes(4)), 0, 7);

        try {
            $created = $this->client->post('/vector/r/tickets', body: [
                'number' => $number, 'subject' => 'Empty box', 'body' => json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Nothing inside.']]]]], JSON_THROW_ON_ERROR),
                'status' => 'open', 'priority' => 'low', 'channel' => 'email', 'customerEmail' => 'box@example.com', 'team' => $teamId,
                'compensationCents' => 0, 'currency' => 'EUR', 'isEscalated' => false, 'metadata' => (object) ['box' => new stdClass(), 'items' => []],
            ], headers: $this->adminHeaders())->assertCreated();
            $answer = json_decode($created->body(), false, 512, JSON_THROW_ON_ERROR);
            self::assertInstanceOf(stdClass::class, $answer);

            self::assertSame('{"box":{},"items":[]}', self::canonical(json_encode($answer->data->metadata, JSON_THROW_ON_ERROR)));
            self::assertSame('{"box":{},"items":[]}', $this->storedMetadata((int) $answer->data->id));
        } finally {
            $this->support()->table('tickets')->where('number', '=', $number)->delete();
        }
    }

    public function testAKeyValueFieldStoresAndAnswersAnEmptyObjectOnMysql(): void
    {
        $products = $this->vector($this->adminHeaders())->resource('products');
        $row = $this->shop()->table('products')->select('id', 'attributes')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);
        $id = (int) $row['id'];

        try {
            $answer = self::objects($products->update($id, ['attributes' => new stdClass()])->assertOk());
            self::assertSame('{}', json_encode($answer->data->attributes, JSON_THROW_ON_ERROR), 'answered as an empty object');
            self::assertSame('{}', $this->storedAttributes($id), 'stored as an empty object');

            $answer = self::objects($products->update($id, ['attributes' => (object) [' size ' => 'M', '0' => 'zero', 'count' => 3]])->assertOk());
            self::assertEquals((object) ['size' => 'M', '0' => 'zero', 'count' => '3'], $answer->data->attributes, 'keys trimmed, values text');
            self::assertEquals((object) ['size' => 'M', '0' => 'zero', 'count' => '3'], json_decode((string) $this->storedAttributes($id), false));

            $products->update($id, ['attributes' => ['a', 'b']])->assertInvalid('attributes', 'type_mismatch');
            $products->update($id, ['attributes' => (object) ['a' => (object) ['b' => 1]]])->assertInvalid('attributes', 'type_mismatch');
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['attributes' => $row['attributes']]);
        }
    }

    public function testACreatedProductKeepsAnObjectOfTextValuesOnMysql(): void
    {
        $categoryId = (int) $this->shop()->table('categories')->select('id')->orderBy('id', 'ASC')->value('id');
        $sku = 'SKU-JSON-' . bin2hex(random_bytes(4));

        try {
            $created = $this->client->post('/vector/r/products', body: [
                'sku' => $sku, 'name' => 'Object product', 'slug' => 'object-product-' . $sku, 'status' => 'draft',
                'category' => $categoryId, 'priceCents' => 1999, 'currency' => 'EUR', 'stock' => 3, 'weightKg' => 0.5,
                'isFeatured' => false, 'attributes' => new stdClass(),
            ], headers: $this->adminHeaders())->assertCreated();
            $answer = json_decode($created->body(), false, 512, JSON_THROW_ON_ERROR);
            self::assertInstanceOf(stdClass::class, $answer);

            self::assertSame('{}', json_encode($answer->data->attributes, JSON_THROW_ON_ERROR));
            self::assertSame('{}', $this->storedAttributes((int) $answer->data->id));
        } finally {
            $this->shop()->table('products')->where('sku', '=', $sku)->delete();
        }
    }

    public function testRequiredRefusesAnEmptyObject(): void
    {
        [$id, $stored] = $this->ticket();

        try {
            $this->vector($this->adminHeaders())->resource('object-tickets')->update($id, ['metadata' => (object) ['kept' => true]])->assertOk();
            $this->vector($this->adminHeaders())->resource('required-object-tickets')->update($id, ['metadata' => new stdClass()])
                ->assertInvalid('metadata', 'not_blank');
            $this->vector($this->adminHeaders())->resource('required-object-tickets')->update($id, ['metadata' => []])
                ->assertInvalid('metadata', 'not_blank');
            self::assertSame('{"kept":true}', $this->storedMetadata($id), 'nothing was written');
        } finally {
            $this->support()->table('tickets')->where('id', '=', $id)->update(['metadata' => $stored]);
        }
    }

    /** A resource with no fields of its own derives a JSON field for a property that keeps objects. */
    public function testADerivedFieldOfAStdClassPropertyIsJson(): void
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));
        $derived = new #[Resource(Product::class, slug: 'derived-object-products')] class extends VectorResource {};
        $field = array_column((new ResourceCompiler($metadata, [$derived::class]))->compile()['derived-object-products']['fields'], null, 'name')['attributes'];

        self::assertSame(['json', 'json', 'object', false], [$field['type'], $field['valueType'], $field['jsonObjects'], $field['sortable']]);
    }

    /** A bulk edit's fields keep their objects as a single update does. */
    public function testABulkEditKeepsANestedEmptyObject(): void
    {
        $rows = $this->support()->table('tickets')->select('id', 'metadata')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->limit(2)->get();
        self::assertCount(2, $rows);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        try {
            $this->vector($this->adminHeaders())->resource('object-tickets')
                ->batch('update', $ids, fields: ['metadata' => (object) ['box' => new stdClass()]])
                ->assertOk();

            foreach ($ids as $id) {
                self::assertSame('{"box":{}}', $this->storedMetadata($id));
            }
        } finally {
            foreach ($rows as $row) {
                $this->support()->table('tickets')->where('id', '=', $row['id'])->update(['metadata' => $row['metadata']]);
            }
        }
    }

    /**
     * Sending back what the API answered changes nothing the ORM compares,
     * so the row is not written: its version stays where it was.
     */
    public function testSendingTheSameDocumentBackWritesNothing(): void
    {
        [$id, $stored] = $this->ticket();
        $tickets = $this->vector($this->adminHeaders())->resource('object-tickets');

        try {
            $first = self::objects($tickets->update($id, ['metadata' => (object) ['box' => new stdClass(), 'list' => [], 'n' => 1]])->assertOk());
            self::assertSame('{"box":{},"list":[],"n":1}', $this->storedMetadata($id));
            $version = $first->data->_version;

            $again = self::objects($tickets->update($id, ['metadata' => $first->data->metadata])->assertOk());
            self::assertSame($version, $again->data->_version, 'no UPDATE: the version did not move');
            self::assertSame('{"box":{},"list":[],"n":1}', $this->storedMetadata($id));

            $changed = self::objects($tickets->update($id, ['metadata' => (object) ['box' => new stdClass(), 'list' => [], 'n' => 2]])->assertOk());
            self::assertSame($version + 1, $changed->data->_version, 'a real change is written');
        } finally {
            $this->support()->table('tickets')->where('id', '=', $id)->update(['metadata' => $stored]);
        }
    }

    /** A revert from the audit trail puts back the object a change replaced, empty or nested. */
    public function testARevertRestoresAnObject(): void
    {
        [$id, $stored] = $this->ticket();
        $tickets = $this->vector($this->adminHeaders())->resource('object-tickets');

        try {
            foreach (['{}' => new stdClass(), '{"a":{}}' => (object) ['a' => new stdClass()]] as $json => $old) {
                $tickets->update($id, ['metadata' => $old])->assertOk();
                $tickets->update($id, ['metadata' => [1, 2]])->assertOk();
                self::assertSame('[1,2]', $this->storedMetadata($id));

                $trail = json_decode($this->client->get("/vector/r/object-tickets/{$id}/audit", headers: $this->adminHeaders())->assertOk()->body(), false, 512, JSON_THROW_ON_ERROR);
                self::assertInstanceOf(stdClass::class, $trail);
                $entry = $trail->data[0];
                self::assertSame($json, json_encode($entry->changes->metadata->from, JSON_THROW_ON_ERROR), "{$json}: the trail answers the object");

                $reverted = json_decode($this->client->post("/vector/r/object-tickets/{$id}/audit/{$entry->id}/revert", headers: $this->adminHeaders())->assertOk()->body(), false, 512, JSON_THROW_ON_ERROR);
                self::assertInstanceOf(stdClass::class, $reverted);
                self::assertSame($json, json_encode($reverted->data->metadata, JSON_THROW_ON_ERROR), "{$json}: answered");
                self::assertSame($json, $this->storedMetadata($id), "{$json}: stored");
            }
        } finally {
            $this->support()->table('tickets')->where('id', '=', $id)->update(['metadata' => $stored]);
        }
    }

    /**
     * A document exactly as deep as JsonDepth allows the field is saved,
     * audited and answered, by the record and by its audit trail; one level
     * deeper is a 422 on the field and writes nothing. The ticket lives on
     * PostgreSQL (2499 levels); the audit row, which wraps the value in two
     * more, on Vector's connection: PostgreSQL, or MySQL (100 levels) when
     * VECTOR_TEST_DB_CONNECTION puts Vector's tables there. Written through
     * the writer itself: a REST body nests at most 64 levels.
     */
    public function testAValueAtTheJsonDepthReadsBackAndOneDeeperIsRefused(): void
    {
        [$id, $stored] = $this->ticket();
        $vector = $this->app->get(VectorDatabase::class);
        self::assertInstanceOf(VectorDatabase::class, $vector);
        $registry = $this->app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $resource = $registry->resource('object-tickets');
        $scope = $this->app->createRequestScope();
        $scope->instance(CurrentUserInterface::class, new DemoActor(1, 'Test', true));
        $lastEntry = (int) $this->vectorQuery()->table('vector_audit')->select('id')->orderBy('id', 'DESC')->value('id');

        try {
            $depth = $scope->get(JsonDepth::class);
            self::assertInstanceOf(JsonDepth::class, $depth);
            $max = $depth->forField($resource);
            // MySQL's 100 less the audit's two; on PostgreSQL, what the audit trail's answer still carries
            self::assertSame($vector->isPostgres() ? 507 : 98, $max);
            $writer = $scope->get(RecordWriter::class);
            self::assertInstanceOf(RecordWriter::class, $writer);

            $deepest = self::nested($max);
            $writer->update($resource, (string) $id, ['metadata' => $deepest], null);
            $expected = json_encode($deepest, JSON_THROW_ON_ERROR, $max);
            self::assertSame($expected, json_encode(self::objects($this->vector($this->adminHeaders())->resource('object-tickets')->show($id)->assertOk())->data->metadata, JSON_THROW_ON_ERROR, $max), 'the record');
            $trail = json_decode($this->client->get("/vector/r/object-tickets/{$id}/audit", headers: $this->adminHeaders())->assertOk()->body(), false, JsonDepth::ANSWER_DEPTH + 1, JSON_THROW_ON_ERROR);
            self::assertInstanceOf(stdClass::class, $trail);
            self::assertSame($expected, json_encode($trail->data[0]->changes->metadata->to, JSON_THROW_ON_ERROR, $max), 'its audit row');

            try {
                $writer->update($resource, (string) $id, ['metadata' => self::nested($max + 1)], null);
                self::fail('A document one level deeper was written.');
            } catch (ValidationException $e) {
                self::assertSame([[['metadata'], 'too_deep', ['max' => $max]]], array_map(static fn (Violation $v): array => [$v->path, $v->code, $v->parameters], $e->violations));
            }

            self::assertSame(self::canonical($expected), $this->storedMetadata($id), 'nothing was written');
        } finally {
            $scope->dispose();
            $this->support()->table('tickets')->where('id', '=', $id)->update(['metadata' => $stored]);
            // a trail this deep is more than a client decoding at json_decode()'s default reads: other tests read this ticket's
            $this->vectorQuery()->table('vector_audit')->where('resource', '=', 'object-tickets')->where('record_id', '=', (string) $id)->where('id', '>', $lastEntry)->delete();
        }
    }

    /** The MCP create and update tools keep a field's objects as a REST body does. */
    public function testTheMcpToolsKeepANestedEmptyObject(): void
    {
        [$id, $stored] = $this->ticket();
        $teamId = (int) $this->support()->table('support_teams')->select('id')->orderBy('id', 'ASC')->value('id');
        $number = 'TCK-M' . substr(bin2hex(random_bytes(4)), 0, 7);

        try {
            $updated = $this->mcp('vector_update', ['resource' => 'object-tickets', 'id' => (string) $id, 'values' => ['metadata' => (object) ['box' => new stdClass(), 'list' => []]]]);
            self::assertSame('{"box":{},"list":[]}', self::canonical(json_encode($updated->data->metadata, JSON_THROW_ON_ERROR)), 'update: answered');
            self::assertSame('{"box":{},"list":[]}', $this->storedMetadata($id), 'update: stored');
            self::assertSame('{"box":{},"list":[]}', self::canonical(json_encode(self::objects($this->vector($this->adminHeaders())->resource('tickets')->show($id)->assertOk())->data->metadata, JSON_THROW_ON_ERROR)), 'update: read back');

            $created = $this->mcp('vector_create', ['resource' => 'tickets', 'values' => [
                'number' => $number, 'subject' => 'Empty box', 'body' => json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Nothing inside.']]]]], JSON_THROW_ON_ERROR),
                'status' => 'open', 'priority' => 'low', 'channel' => 'email', 'customerEmail' => 'box@example.com', 'team' => $teamId,
                'compensationCents' => 0, 'currency' => 'EUR', 'isEscalated' => false, 'metadata' => (object) ['box' => new stdClass(), 'items' => []],
            ]]);
            self::assertSame('{"box":{},"items":[]}', self::canonical(json_encode($created->data->metadata, JSON_THROW_ON_ERROR)), 'create: answered');
            self::assertSame('{"box":{},"items":[]}', $this->storedMetadata((int) $created->data->id), 'create: stored');
        } finally {
            $this->support()->table('tickets')->where('id', '=', $id)->update(['metadata' => $stored]);
            $this->support()->table('tickets')->where('number', '=', $number)->delete();
        }
    }

    /** A field that keeps no objects (a JSON repeater's rows) receives arrays from the MCP tools too, as from a REST body. */
    public function testTheMcpToolsHandAFieldThatKeepsNoObjectsArrays(): void
    {
        $row = $this->shop()->table('products')->select('id', 'specs')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);
        $id = (int) $row['id'];

        try {
            $answer = $this->mcp('vector_update', ['resource' => 'products', 'id' => (string) $id, 'values' => ['specs' => [(object) ['label' => 'Box', 'value' => 'none']]]]);
            self::assertSame('[{"label":"Box","value":"none"}]', self::canonical(json_encode($answer->data->specs, JSON_THROW_ON_ERROR)));
            self::assertSame('[{"label":"Box","value":"none"}]', self::canonical((string) $this->shop()->table('products')->select('specs')->where('id', '=', $id)->value('specs')));
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['specs' => $row['specs']]);
        }
    }

    /**
     * A relationship repeater's row keeps the objects of its child's JSON
     * field, through a REST update and the MCP update tool. Every row
     * names a reply the ticket holds, so the save only changes them.
     */
    public function testARelationshipRepeaterRowKeepsItsObjects(): void
    {
        [$ticketId, $stored] = $this->ticketReplies();
        $first = array_key_first($stored);
        $tickets = $this->vector($this->adminHeaders())->resource('replied-tickets');
        $rows = static fn (mixed $metadata): array => array_map(static fn (int $id): array => $id === $first ? ['id' => $id, 'metadata' => $metadata] : ['id' => $id], array_keys($stored));

        try {
            foreach ([
                'an empty object' => [new stdClass(), '{}'],
                'a nested empty object' => [(object) ['a' => new stdClass()], '{"a":{}}'],
                'a list' => [[1, new stdClass(), []], '[1,{},[]]'],
                'an object' => [(object) ['a' => (object) ['b' => new stdClass()], 'list' => [], 'n' => 1], '{"a":{"b":{}},"list":[],"n":1}'],
            ] as $case => [$sent, $json]) {
                $tickets->update($ticketId, ['replies' => $rows($sent)])->assertOk();
                self::assertSame($json, $this->storedReplyMetadata($first), "{$case}: stored");
                self::assertSame($json, self::canonical(json_encode(self::objects($this->vector($this->adminHeaders())->resource('object-replies')->show($first)->assertOk())->data->metadata, JSON_THROW_ON_ERROR)), "{$case}: read back");
            }

            $this->mcp('vector_update', ['resource' => 'replied-tickets', 'id' => (string) $ticketId, 'values' => ['replies' => $rows((object) ['box' => new stdClass()])]]);
            self::assertSame('{"box":{}}', $this->storedReplyMetadata($first), 'MCP: stored');
        } finally {
            foreach ($stored as $id => $metadata) {
                $this->support()->table('ticket_replies')->where('id', '=', $id)->update(['metadata' => $metadata]);
            }
        }
    }

    /**
     * A row's JSON key PHP cannot hold as a property (one starting with
     * NUL) is refused at its row and field, as at the top level, rather
     * than failing the request.
     */
    public function testARelationshipRepeaterRowRefusesANulKey(): void
    {
        [$ticketId, $stored] = $this->ticketReplies();
        $first = array_key_first($stored);
        $rows = array_map(static fn (int $id): array => $id === $first ? ['id' => $id, 'metadata' => ["\0a" => 1]] : ['id' => $id], array_keys($stored));

        $errors = $this->vector($this->adminHeaders())->resource('replied-tickets')->update($ticketId, ['replies' => $rows])->assertStatus(422)->json()['errors'] ?? null;

        self::assertIsArray($errors);
        self::assertContains(['replies', 0, 'metadata'], array_column($errors, 'path'), (string) json_encode($errors));
        self::assertSame($stored[$first] === null ? null : self::canonical($stored[$first]), $this->storedReplyMetadata($first), 'nothing was written');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->supportManager?->close();
        $this->supportManager = null;
        parent::tearDown();
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }

    private function support(): Query
    {
        if ($this->supportManager === null) {
            $factories = $this->app->get(OrmFactoryRegistry::class);
            self::assertInstanceOf(OrmFactoryRegistry::class, $factories);
            $this->supportManager = $factories->factory('support')->open();
        }

        return $this->supportManager->builder();
    }

    /** @return array{0: int, 1: string|null} */
    private function ticket(): array
    {
        $row = $this->support()->table('tickets')->select('id', 'metadata')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);

        return [(int) $row['id'], $row['metadata'] === null ? null : (string) $row['metadata']];
    }

    /**
     * A ticket with replies, and each reply's stored metadata, the first
     * reply first.
     *
     * @return array{0: int, 1: non-empty-array<int, string|null>}
     */
    private function ticketReplies(): array
    {
        $ticketId = (int) $this->support()->table('ticket_replies')->select('ticket_id')->orderBy('id', 'ASC')->value('ticket_id');
        $stored = [];

        foreach ($this->support()->table('ticket_replies')->select('id', 'metadata')->where('ticket_id', '=', $ticketId)->orderBy('id', 'ASC')->get() as $row) {
            $stored[(int) $row['id']] = $row['metadata'] === null ? null : (string) $row['metadata'];
        }

        if ($stored === []) {
            self::fail('No ticket has replies.');
        }

        return [$ticketId, $stored];
    }

    private function storedReplyMetadata(int $id): ?string
    {
        $value = $this->support()->table('ticket_replies')->select('metadata')->where('id', '=', $id)->value('metadata');

        return $value === null ? null : self::canonical((string) $value);
    }

    private function storedMetadata(int $id): ?string
    {
        $value = $this->support()->table('tickets')->select('metadata')->where('id', '=', $id)->value('metadata');

        return $value === null ? null : self::canonical((string) $value);
    }

    private function storedAttributes(int $id): ?string
    {
        $value = $this->shop()->table('products')->select('attributes')->where('id', '=', $id)->value('attributes');

        return $value === null ? null : self::canonical((string) $value);
    }

    /** Objects nested $levels deep, the innermost holding a number. */
    private static function nested(int $levels): stdClass
    {
        $value = (object) ['n' => 1];

        for ($level = 1; $level < $levels; $level++) {
            $value = (object) ['a' => $value];
        }

        return $value;
    }

    /** JSON text with every object kept and its keys sorted, so a server's own key order is no difference. */
    private static function canonical(string $json): string
    {
        return json_encode(self::sorted(json_decode($json, false, 512, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
    }

    private static function sorted(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::sorted(...), $value);
        }

        if (!$value instanceof stdClass) {
            return $value;
        }

        $members = get_object_vars($value);
        ksort($members, SORT_STRING);
        $sorted = new stdClass();

        foreach ($members as $key => $member) {
            $sorted->{(string) $key} = self::sorted($member);
        }

        return $sorted;
    }

    /**
     * A successful MCP tool call's answer, with every JSON object a stdClass.
     *
     * @param array<string, mixed> $arguments
     */
    private function mcp(string $tool, array $arguments): stdClass
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]], JSON_THROW_ON_ERROR);
        $headers = [...$this->adminHeaders(), 'MCP-Protocol-Version' => '2025-06-18', 'Content-Type' => 'application/json'];
        $response = json_decode($this->client->raw('POST', '/mcp', $body, $headers)->assertOk()->body(), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $response);
        self::assertObjectHasProperty('result', $response, json_encode($response, JSON_THROW_ON_ERROR));
        self::assertFalse($response->result->isError ?? false, $response->result->content[0]->text ?? '');
        $answer = json_decode($response->result->content[0]->text, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $answer);

        return $answer;
    }

    /** The response body with every JSON object a stdClass. */
    private static function objects(VectorResponse $response): stdClass
    {
        $decoded = json_decode($response->raw()->body(), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $decoded);

        return $decoded;
    }
}

/** The ticket's metadata alone, with nothing stamped on an update. */
#[Resource(Ticket::class, slug: 'object-tickets')]
final class ObjectTickets extends VectorResource
{
    public function fields(): array
    {
        return [Id::make(), Json::make('metadata')];
    }

    #[\Override]
    public function timestamps(): array
    {
        return ['created' => null, 'updated' => null];
    }
}

/** The ticket's metadata, required. */
#[Resource(Ticket::class, slug: 'required-object-tickets')]
final class RequiredObjectTickets extends VectorResource
{
    public function fields(): array
    {
        return [Id::make(), Json::make('metadata')->required()];
    }

    #[\Override]
    public function timestamps(): array
    {
        return ['created' => null, 'updated' => null];
    }
}

/** A ticket whose replies are a relationship repeater's rows. */
#[Resource(Ticket::class, slug: 'replied-tickets')]
final class RepliedTickets extends VectorResource
{
    public function fields(): array
    {
        return [Id::make(), Repeater::make('replies')->relationship('replies', ObjectReplies::class)->fields(['metadata'])->max(500)];
    }

    #[\Override]
    public function timestamps(): array
    {
        return ['created' => null, 'updated' => null];
    }
}

/** A reply's metadata (`array|stdClass|null`) as a JSON field, the row of RepliedTickets' repeater. */
#[Resource(TicketReply::class, slug: 'object-replies')]
final class ObjectReplies extends VectorResource
{
    public function fields(): array
    {
        return [Id::make(), Json::make('metadata')];
    }
}
