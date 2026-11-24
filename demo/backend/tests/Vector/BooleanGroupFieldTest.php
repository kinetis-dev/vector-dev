<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\CustomerProfile;
use App\Domain\Product;
use App\Vector\CustomerResource;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Database\Connections;
use Kinetis\Vector\Database\JsonFilter;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\BelongsTo;
use Kinetis\Vector\Fields\Boolean;
use Kinetis\Vector\Fields\BooleanGroup;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Testing\InteractsWithVector;
use Kinetis\Vector\VectorResource;

/**
 * The flag group: in columns mode each option a bool property with a
 * column of its own, filtered and sorted as `group.key`; in JSON mode
 * one object of key to bool in a JSON column, filtered by "any" or "all"
 * of its keys being on. Strict about its keys on write and, in JSON
 * mode, on read.
 */
final class BooleanGroupFieldTest extends VectorTestCase
{
    use InteractsWithVector;

    private const array CONTACT = ['newsletter' => 'Newsletter', 'acceptsSms' => 'Text message', 'acceptsPost' => 'Post'];

    private ?EntityManager $supportManager = null;

    public function testStorageMustMatchTheMode(): void
    {
        $cases = [
            'a key that is not a property' => [new #[Resource(CustomerProfile::class, slug: 'flag-profiles')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), BooleanGroup::make('contact')->options(['newsletter' => 'N', 'acceptsFax' => 'Fax'])];
                }
            }, 'names the flag "acceptsFax", which is not a bool property of App\Domain\CustomerProfile; in columns mode every option key is one, or call json()'],
            'a key that is a string property' => [new #[Resource(CustomerProfile::class, slug: 'flag-profiles')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), BooleanGroup::make('contact')->options(['city' => 'City'])];
                }
            }, 'names the flag "city", which is not a bool property'],
            'columns mode named after a property' => [new #[Resource(CustomerProfile::class, slug: 'flag-profiles')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), BooleanGroup::make('country')->options(['newsletter' => 'N'])];
                }
            }, 'is a flag group in columns mode, which maps one bool property per option, but "country" is a property itself; call json()'],
            'a flag that is also a field' => [new #[Resource(CustomerProfile::class, slug: 'flag-profiles')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Boolean::make('newsletter'), BooleanGroup::make('contact')->options(['newsletter' => 'N'])];
                }
            }, 'names the flag "newsletter", which is also a field of the resource'],
            'JSON mode on a string property' => [new #[Resource(CustomerProfile::class, slug: 'flag-profiles')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), BooleanGroup::make('city')->options(['a' => 'A'])->json()];
                }
            }, 'is a flag group stored as JSON, which needs an array or ?array property, not string'],
            'a sorted JSON mode' => [new #[Resource(Product::class, slug: 'flag-profiles')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), BooleanGroup::make('channels')->options(['a' => 'A'])->json()->sortable()];
                }
            }, 'is a flag group stored as JSON, which cannot be sorted'],
            'no options' => [new #[Resource(Product::class, slug: 'flag-profiles')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), BooleanGroup::make('channels')->json()];
                }
            }, 'is a flag group with no options; call options()'],
            'a default naming another key' => [new #[Resource(Product::class, slug: 'flag-profiles')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), BooleanGroup::make('channels')->options(['a' => 'A'])->json()->default(['b' => true])];
                }
            }, 'whose default is an object of some of its option keys to true or false'],
        ];

        foreach ($cases as $case => [$resource, $message]) {
            try {
                $this->compile($resource);
                self::fail("compiled: {$case}");
            } catch (ResourceCompileException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $case);
            }
        }

        $compiled = $this->compile(new #[Resource(CustomerProfile::class, slug: 'flag-profiles')] class extends VectorResource {
            public function fields(): array
            {
                return [
                    Id::make(),
                    BelongsTo::to('customer', CustomerResource::class),
                    Text::make('addressLine'),
                    Text::make('city'),
                    Text::make('postalCode'),
                    Text::make('country'),
                    BooleanGroup::make('contact')->options(['newsletter' => 'Newsletter', 'acceptsSms' => 'SMS'])->sortable()->filterable(),
                ];
            }
        }, CustomerResource::class);
        $contact = array_column($compiled['fields'], null, 'name')['contact'];

        self::assertSame(['newsletter' => 'newsletter', 'acceptsSms' => 'accepts_sms'], $contact['flags']);
        self::assertSame([false, true, 'columns', ['any', 'all']], [$contact['sortable'], $contact['flagsSortable'], $contact['storage'], $contact['operators']]);
        self::assertSame('accepts_sms', $compiled['columns']['contact.acceptsSms'], 'each flag sorts by its own column');
        self::assertTrue($compiled['creation']['possible'], 'the group writes the flags a new profile needs');
        self::assertArrayNotHasKey('contact', $compiled['columns']);
    }

    public function testAnOptionKeyIsAPlainName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"contact": the option key "send-sms" is not a name of letters, digits and underscores.');
        BooleanGroup::make('contact')->options(['send-sms' => 'SMS']);
    }

    public function testColumnsModeWritesEachFlagAndFiltersAndSortsByEach(): void
    {
        $profiles = $this->vector($this->realAdminHeaders())->resource('customer-profiles');
        $row = $this->shop()->table('customer_profiles')->select('id', 'newsletter', 'accepts_sms', 'accepts_post')->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);
        $id = (int) $row['id'];

        try {
            $record = $profiles->update($id, ['contact' => ['acceptsSms' => true, 'newsletter' => true]])->assertOk()->record();
            self::assertSame(['newsletter' => true, 'acceptsSms' => true, 'acceptsPost' => false], $record['contact'], 'every option, a key left out false');
            self::assertSame([1, 1, 0], $this->storedFlags($id));

            $refused = $profiles->update($id, ['contact' => ['acceptsFax' => true]])->assertStatus(422)->json();
            self::assertSame([['contact', 'acceptsFax'], 'invalid_choice'], [$refused['errors'][0]['path'], $refused['errors'][0]['code']]);
            $typed = $profiles->update($id, ['contact' => ['newsletter' => 'yes']])->assertStatus(422)->json();
            self::assertSame([['contact', 'newsletter'], 'type_mismatch'], [$typed['errors'][0]['path'], $typed['errors'][0]['code']]);
            $profiles->update($id, ['contact' => ['newsletter', 'acceptsSms']])->assertInvalid('contact', 'type_mismatch');
            self::assertSame([1, 1, 0], $this->storedFlags($id), 'nothing was written');
        } finally {
            $this->shop()->table('customer_profiles')->where('id', '=', $id)->update(['newsletter' => $row['newsletter'], 'accepts_sms' => $row['accepts_sms'], 'accepts_post' => $row['accepts_post']]);
        }

        $flags = [];

        foreach ($this->shop()->table('customer_profiles')->select('id', 'newsletter', 'accepts_sms', 'accepts_post')->get() as $r) {
            $flags[(int) $r['id']] = ['newsletter' => (bool) $r['newsletter'], 'acceptsSms' => (bool) $r['accepts_sms'], 'acceptsPost' => (bool) $r['accepts_post']];
        }

        $this->assertKeyFilters('customer-profiles', 'contact', $flags, ['acceptsSms', 'acceptsPost']);

        $h = $this->adminHeaders();
        $on = array_keys(array_filter($flags, static fn (array $f): bool => $f['acceptsSms']));
        $off = array_keys(array_filter($flags, static fn (array $f): bool => !$f['acceptsSms']));
        self::assertSame($on, $this->ids('customer-profiles', ['filter' => ['contact.acceptsSms' => 'true']], $h), 'a flag filters on its own');
        self::assertSame($off, $this->ids('customer-profiles', ['filter' => ['contact.acceptsSms' => ['eq' => 'false']]], $h));
        $this->client->get('/vector/r/customer-profiles', query: ['filter' => ['contact.acceptsFax' => 'true']], headers: $h)->assertStatus(422);
        $this->client->get('/vector/r/customer-profiles', query: ['filter' => ['contact' => ['eq' => 'acceptsSms']]], headers: $h)->assertStatus(422);

        $sorted = $this->client->get('/vector/r/customer-profiles', query: ['sort' => '-contact.acceptsPost,id', 'perPage' => 100], headers: $h)->assertOk()->json();
        $posted = array_column(array_column($sorted['data'], 'contact'), 'acceptsPost');
        self::assertSame([...array_filter($posted), ...array_filter($posted, static fn (bool $p): bool => !$p)], $posted, 'the flag on first');
        self::assertContains(true, $posted);
        $this->client->get('/vector/r/customer-profiles', query: ['sort' => 'contact'], headers: $h)->assertStatus(422);
    }

    public function testJsonModeIsStrictAndFiltersByKeysOnPostgres(): void
    {
        $tickets = $this->vector($this->realAdminHeaders())->resource('tickets');
        $row = $this->support()->table('tickets')->select('id', 'flags')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);
        $id = (int) $row['id'];

        try {
            $record = $tickets->update($id, ['flags' => ['legalHold' => true]])->assertOk()->record();
            self::assertSame(['vip' => false, 'refundRequested' => false, 'legalHold' => true], $record['flags']);
            self::assertEquals(['vip' => false, 'refundRequested' => false, 'legalHold' => true], json_decode((string) $this->support()->table('tickets')->select('flags')->where('id', '=', $id)->value('flags'), true));

            $refused = $tickets->update($id, ['flags' => ['escalated' => true]])->assertStatus(422)->json();
            self::assertSame([['flags', 'escalated'], 'invalid_choice'], [$refused['errors'][0]['path'], $refused['errors'][0]['code']]);

            // a key left out of the stored object reads false; one that is no option throws
            $this->support()->table('tickets')->where('id', '=', $id)->update(['flags' => '{"vip": true}']);
            self::assertSame(['vip' => true, 'refundRequested' => false, 'legalHold' => false], $this->client->get("/vector/r/tickets/{$id}", headers: $this->realAdminHeaders())->assertOk()->json()['data']['flags']);
            $this->support()->table('tickets')->where('id', '=', $id)->update(['flags' => '{"vip": true, "escalated": true}']);
            $this->client->get("/vector/r/tickets/{$id}", headers: $this->realAdminHeaders())->assertStatus(500);
        } finally {
            $this->support()->table('tickets')->where('id', '=', $id)->update(['flags' => $row['flags']]);
        }

        $flags = [];

        foreach ($this->support()->table('tickets')->select('id', 'flags')->where('deleted_at', '=', null)->get() as $r) {
            $flags[(int) $r['id']] = $r['flags'] === null ? [] : json_decode((string) $r['flags'], true, 4, JSON_THROW_ON_ERROR);
        }

        ksort($flags);
        $this->assertKeyFilters('tickets', 'flags', $flags, ['vip', 'refundRequested']);
        $h = $this->adminHeaders();
        self::assertSame(array_keys(array_filter($flags, static fn (array $f): bool => $f === [])), $this->ids('tickets', ['filter' => ['flags' => ['null' => '1']]], $h));
        $this->client->get('/vector/r/tickets', query: ['filter' => ['flags' => ['any' => 'vip,escalated']]], headers: $h)->assertStatus(422);
    }

    public function testTheKeysTrueSqlPerDialect(): void
    {
        self::assertSame(
            ['sql' => '(JSON_CONTAINS(`flags`, ?) OR JSON_CONTAINS(`flags`, ?))', 'params' => ['{"vip":true}', '{"legalHold":true}']],
            JsonFilter::keysTrue(Connections::MYSQL, 'flags', JsonFilter::ANY, ['vip', 'legalHold']),
        );
        self::assertSame(
            ['sql' => 'JSON_CONTAINS(`flags`, ?)', 'params' => ['{"vip":true,"legalHold":true}']],
            JsonFilter::keysTrue(Connections::MYSQL, 'flags', JsonFilter::ALL, ['vip', 'legalHold']),
        );
        self::assertSame(
            ['sql' => '("flags" @> ?::jsonb OR "flags" @> ?::jsonb)', 'params' => ['{"vip":true}', '{"it\'s":true}']],
            JsonFilter::keysTrue(Connections::PGSQL, 'flags', JsonFilter::ANY, ['vip', 'it\'s']),
            'a key travels only inside a bound value',
        );
        self::assertSame(
            ['sql' => '"flags" @> ?::jsonb', 'params' => ['{"vip":true,"0":true}']],
            JsonFilter::keysTrue(Connections::PGSQL, 'flags', JsonFilter::ALL, ['vip', '0']),
            'a digit key stays an object key',
        );

        foreach ([['some', ['vip']], ['all', []]] as [$mode, $keys]) {
            try {
                JsonFilter::keysTrue(Connections::MYSQL, 'flags', $mode, $keys);
                self::fail("accepted {$mode}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOpenApiAndMcpDescribeBothModes(): void
    {
        $document = $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($document);
        $contact = self::property($document['components']['schemas'], 'contact');
        $flags = self::property($document['components']['schemas'], 'flags');

        self::assertSame(['object', array_keys(self::CONTACT), false], [$contact['type'], $contact['required'], $contact['additionalProperties']]);
        self::assertSame(['type' => 'boolean'], $contact['properties']['acceptsSms']);
        self::assertSame(['object', 'null'], $flags['type'], 'a nullable JSON column');

        $parameters = [];
        $sorts = [];

        foreach ($document['paths'] as $operations) {
            foreach ($operations['get']['parameters'] ?? [] as $parameter) {
                $parameters[$parameter['name']] = $parameter;
                $sorts = [...$sorts, ...($parameter['name'] === 'sort' ? $parameter['schema']['items']['enum'] : [])];
            }
        }

        foreach (['filter[contact][any]', 'filter[contact][all]', 'filter[contact.acceptsSms][eq]', 'filter[flags][any]', 'filter[flags][null]'] as $name) {
            self::assertArrayHasKey($name, $parameters);
        }

        self::assertSame(['type' => 'boolean'], $parameters['filter[contact.acceptsSms][eq]']['schema']);
        self::assertStringContainsString('flags on', $parameters['filter[flags][all]']['schema']['description']);
        self::assertContains('-contact.newsletter', $sorts, 'each flag sorts');
        self::assertNotContains('contact', $sorts);

        $profile = array_column($this->mcpTool('vector_describe', ['resource' => 'customer-profiles'])['fields'], null, 'name')['contact'];
        self::assertSame(['booleanGroup', ['any', 'all']], [$profile['type'], $profile['filter']]);
        self::assertStringContainsString('object of option key to true or false', $profile['format']);
        self::assertStringContainsString('filter[contact.<key>]=true or false', $profile['filterHelp']);
        self::assertSame([['name' => 'contact.newsletter', 'filter' => ['eq'], 'sortable' => true], ['name' => 'contact.acceptsSms', 'filter' => ['eq'], 'sortable' => true], ['name' => 'contact.acceptsPost', 'filter' => ['eq'], 'sortable' => true]], $profile['flags']);
        self::assertSame(array_keys(self::CONTACT), array_column($profile['options'], 'value'));

        $ticket = array_column($this->mcpTool('vector_describe', ['resource' => 'tickets'])['fields'], null, 'name')['flags'];
        self::assertSame(['booleanGroup', ['any', 'all', 'null', 'nnull']], [$ticket['type'], $ticket['filter']]);
        self::assertArrayNotHasKey('flags', $ticket);
        self::assertStringNotContainsString('filter[flags.', $ticket['filterHelp']);
    }

    /**
     * "any" and "all" of each key alone and of the pair match exactly the
     * records with those flags on, as PHP reads the rows.
     *
     * @param array<int, array<string, bool>> $flags record id => its flags
     * @param array{0: string, 1: string} $pair
     */
    private function assertKeyFilters(string $slug, string $field, array $flags, array $pair): void
    {
        $h = $this->adminHeaders();
        ksort($flags);
        $on = static fn (array $f, string $key): bool => ($f[$key] ?? false) === true;
        $any = array_keys(array_filter($flags, static fn (array $f): bool => $on($f, $pair[0]) || $on($f, $pair[1])));
        $all = array_keys(array_filter($flags, static fn (array $f): bool => $on($f, $pair[0]) && $on($f, $pair[1])));

        self::assertNotSame([], $all, 'the seed holds a record with both flags on');
        self::assertGreaterThan(count($all), count($any), 'the seed tells "any" from "all"');
        self::assertSame($any, $this->ids($slug, ['filter' => [$field => ['any' => implode(',', $pair)]]], $h), "{$slug} {$field} any");
        self::assertSame($all, $this->ids($slug, ['filter' => [$field => ['all' => implode(',', $pair)]]], $h), "{$slug} {$field} all");
        self::assertSame($any, $this->ids($slug, ['filter' => [$field => implode(',', $pair)]], $h), 'a bare value is "any"');

        foreach ($pair as $key) {
            self::assertSame(array_keys(array_filter($flags, static fn (array $f): bool => $on($f, $key))), $this->ids($slug, ['filter' => [$field => ['all' => $key]]], $h), "{$slug} {$field} {$key}");
        }

        $grouped = $this->ids($slug, ['filter' => ['or' => [[$field => ['all' => implode(',', $pair)]], [$field => ['all' => $pair[0]]]]]], $h);
        self::assertSame(array_keys(array_filter($flags, static fn (array $f): bool => $on($f, $pair[0]))), $grouped, 'inside an or group');
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @return list<int>
     */
    private function ids(string $slug, array $query, array $headers): array
    {
        $ids = [];
        $page = 1;

        do {
            $body = $this->client->get("/vector/r/{$slug}", query: [...$query, 'perPage' => 100, 'page' => $page, 'columns' => 'id'], headers: $headers)->assertOk()->json();
            self::assertIsArray($body);
            $ids = [...$ids, ...array_map(static fn (array $r): int => (int) $r['id'], $body['data'])];
            $page++;
        } while (count($body['data']) === 100);

        sort($ids);

        return $ids;
    }

    /**
     * @param class-string ...$others the resources its relations point to
     * @return array<string, mixed>
     */
    private function compile(VectorResource $resource, string ...$others): array
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));

        return (new ResourceCompiler($metadata, [$resource::class, ...array_values($others)]))->compile()['flag-profiles'];
    }

    /** @return list<int> newsletter, accepts_sms and accepts_post as stored */
    private function storedFlags(int $id): array
    {
        $row = $this->shop()->table('customer_profiles')->select('newsletter', 'accepts_sms', 'accepts_post')->where('id', '=', $id)->first();
        self::assertIsArray($row);

        return [(int) $row['newsletter'], (int) $row['accepts_sms'], (int) $row['accepts_post']];
    }

    /**
     * The schema of the first record carrying $name as an object.
     *
     * @param array<string, mixed> $schemas
     * @return array<string, mixed>
     */
    private static function property(array $schemas, string $name): array
    {
        foreach ($schemas as $schema) {
            $property = is_array($schema) ? $schema['properties'][$name] ?? null : null;

            if (is_array($property) && in_array('object', (array) ($property['type'] ?? []), true) && isset($property['required'])) {
                return $property;
            }
        }

        self::fail("No record schema carries {$name}.");
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function mcpTool(string $tool, array $arguments): array
    {
        $headers = [...$this->adminHeaders(), 'MCP-Protocol-Version' => '2025-06-18', 'Content-Type' => 'application/json'];
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]], JSON_THROW_ON_ERROR);
        $response = $this->client->raw('POST', '/mcp', $body, $headers)->assertOk()->json();
        self::assertIsArray($response);
        $result = $response['result'] ?? self::fail(json_encode($response, JSON_THROW_ON_ERROR));
        self::assertFalse($result['isError'] ?? false, $result['content'][0]['text'] ?? '');
        $decoded = json_decode($result['content'][0]['text'], true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
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
