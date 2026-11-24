<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Testing\TestResponse;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Testing\InteractsWithVector;

/**
 * A JSON document keeps its shape through a write: an object sent stays
 * an object, an empty one included, when it is stored and read back, and
 * a list stays a list. A key-value field always stores an object.
 */
final class JsonShapeTest extends VectorTestCase
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

        // a product's attributes as a key-value field on a column that may not hold null
        foreach ($data['resources']['products']['fields'] as $i => $field) {
            if ($field['name'] === 'attributes') {
                $data['resources']['products']['fields'][$i]['nullable'] = false;
            }
        }

        $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));
    }

    public function testAJsonDocumentKeepsItsShapeOnCreateAndUpdate(): void
    {
        $created = $this->send('POST', '/vector/r/tickets', $this->ticket('{}'))->assertCreated();
        $id = (int) $this->data($created)->id;

        try {
            self::assertSame('{}', $this->metadataOf($created), 'an empty object is answered as one');
            self::assertSame('{}', $this->storedMetadata($id), 'and stored as one');
            self::assertSame('{}', $this->metadataOf($this->client->get("/vector/r/tickets/{$id}", headers: $this->realAdminHeaders())->assertOk()), 'and read back as one');

            foreach (['{"box":{},"carrier":"DHL","parcels":[]}', '[]', '[{},1]', '{"carrier":"DHL"}', '{}', '{"0":"a"}'] as $document) {
                $updated = $this->send('PATCH', "/vector/r/tickets/{$id}", '{"metadata":' . $document . '}')->assertOk();
                self::assertSame($document, $this->metadataOf($updated), $document);
                self::assertSame($document, $this->metadataOf($this->client->get("/vector/r/tickets/{$id}", headers: $this->realAdminHeaders())->assertOk()), "{$document} read back");
            }
        } finally {
            $this->support()->table('tickets')->where('id', '=', $id)->delete();
        }

        foreach (['[1,2]', '{"box":{},"carrier":"DHL"}'] as $document) {
            $created = $this->send('POST', '/vector/r/tickets', $this->ticket($document))->assertCreated();
            $id = (int) $this->data($created)->id;
            $this->support()->table('tickets')->where('id', '=', $id)->delete();
            self::assertSame($document, $this->metadataOf($created), "{$document} on create");
        }
    }

    public function testAnEmptyKeyValueStoresAnObject(): void
    {
        $row = $this->shop()->table('products')->select('id', 'attributes')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);
        $id = (int) $row['id'];

        try {
            $updated = $this->send('PATCH', "/vector/r/products/{$id}", '{"attributes":{}}')->assertOk();
            self::assertSame('{}', json_encode($this->data($updated)->attributes, JSON_THROW_ON_ERROR));
            self::assertSame('{}', $this->storedAttributes($id), 'the column holds an object');

            $this->send('PATCH', "/vector/r/products/{$id}", '{"attributes":{"size":"M"}}')->assertOk();
            self::assertSame('{"size":"M"}', $this->storedAttributes($id));
            $this->send('PATCH', "/vector/r/products/{$id}", '{"attributes":{}}')->assertOk();
            self::assertSame('{}', $this->storedAttributes($id), 'emptied again');
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['attributes' => $row['attributes']]);
        }
    }

    private function storedAttributes(int $id): string
    {
        return str_replace(' ', '', (string) $this->shop()->table('products')->select('attributes')->where('id', '=', $id)->value('attributes'));
    }

    private function send(string $method, string $url, string $body): TestResponse
    {
        return $this->client->raw($method, $url, $body, [...$this->realAdminHeaders(), 'Content-Type' => 'application/json']);
    }

    private function data(TestResponse $response): \stdClass
    {
        $decoded = json_decode($response->body(), false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $decoded);
        self::assertInstanceOf(\stdClass::class, $decoded->data);

        return $decoded->data;
    }

    /** A record's metadata as JSON text, its objects kept as objects. */
    private function metadataOf(TestResponse $response): string
    {
        return json_encode($this->data($response)->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** The stored metadata as JSON text, as PostgreSQL normalizes it and PHP encodes it. */
    private function storedMetadata(int $id): string
    {
        return json_encode(json_decode((string) $this->support()->table('tickets')->select('metadata')->where('id', '=', $id)->value('metadata'), false), JSON_THROW_ON_ERROR);
    }

    /** A new ticket's body, its metadata the JSON text $metadata. */
    private function ticket(string $metadata): string
    {
        $teamId = (int) $this->support()->table('support_teams')->select('id')->orderBy('id', 'ASC')->value('id');
        $body = json_encode([
            'number' => 'TCK-J' . substr(bin2hex(random_bytes(4)), 0, 7), 'subject' => 'Shape', 'status' => 'open', 'priority' => 'low', 'channel' => 'web',
            'body' => json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]]]], JSON_THROW_ON_ERROR),
            'customerEmail' => 'shape@example.com', 'team' => $teamId, 'compensationCents' => 0, 'currency' => 'EUR', 'isEscalated' => false,
        ], JSON_THROW_ON_ERROR);

        return substr($body, 0, -1) . ',"metadata":' . $metadata . '}';
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

    protected function tearDown(): void
    {
        $this->supportManager?->close();
        $this->supportManager = null;
        parent::tearDown();
    }
}
