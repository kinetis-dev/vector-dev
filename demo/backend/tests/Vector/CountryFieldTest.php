<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\CustomerProfile;
use App\Domain\Product;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\Country;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Support\Countries;
use Kinetis\Vector\Testing\InteractsWithVector;
use Kinetis\Vector\VectorResource;

/**
 * The country field: a select over the assigned ISO 3166-1 alpha-2
 * codes, named in English on the server, one code in a string column or
 * several in a JSON one, strict about the codes on write.
 */
final class CountryFieldTest extends VectorTestCase
{
    use InteractsWithVector;

    private ?EntityManager $supportManager = null;

    public function testItOffersEveryCodeOrTheOnesOnlyNames(): void
    {
        $all = array_column($this->compile(new #[Resource(CustomerProfile::class, slug: 'country-profiles')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Country::make('country')];
            }
        })['fields'], null, 'name')['country'];

        self::assertSame(['country', Countries::codes(), true, false, 'string'], [$all['type'], array_column($all['options'], 'value'), $all['autocomplete'], $all['multiple'], $all['valueType']]);
        self::assertSame('Germany', array_column($all['options'], 'label', 'value')['DE'], 'named in English');
        self::assertSame(['eq', 'ne', 'in', 'nin'], ResourceCompiler::operatorsFor([...$all, 'filterable' => true]));

        $only = array_column($this->compile(new #[Resource(CustomerProfile::class, slug: 'country-profiles')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Country::make('country')->only(['PT', 'NL', 'DE'])->searchable(false)];
            }
        })['fields'], null, 'name')['country'];
        self::assertSame([['value' => 'PT', 'label' => 'Portugal', 'color' => null], ['value' => 'NL', 'label' => 'Netherlands', 'color' => null], ['value' => 'DE', 'label' => 'Germany', 'color' => null]], $only['options']);
        self::assertFalse($only['autocomplete']);

        $cases = [
            'an unassigned code' => [new #[Resource(CustomerProfile::class, slug: 'country-profiles')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Country::make('country')->only(['NL', 'XX'])];
                }
            }, 'field "country": offers "XX", which is not an assigned ISO 3166-1 alpha-2 country code'],
            'a code in lower case' => [new #[Resource(CustomerProfile::class, slug: 'country-profiles')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Country::make('country')->only(['nl'])];
                }
            }, 'offers "nl", which is not an assigned ISO 3166-1 alpha-2 country code'],
            'several in a string property' => [new #[Resource(CustomerProfile::class, slug: 'country-profiles')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Country::make('country')->multiple()];
                }
            }, 'is a multiple select, which stores a JSON list: declare the property array or ?array, not string'],
            'one in an array property' => [new #[Resource(Product::class, slug: 'country-profiles')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Country::make('channels')];
                }
            }, 'is a single select on an array property; call multiple()'],
        ];

        foreach ($cases as $case => [$resource, $message]) {
            try {
                $this->compile($resource);
                self::fail("compiled: {$case}");
            } catch (ResourceCompileException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $case);
            }
        }
    }

    public function testAnUnassignedCodeIsRefusedOnWrite(): void
    {
        $profiles = $this->vector($this->realAdminHeaders())->resource('customer-profiles');
        $row = $this->shop()->table('customer_profiles')->select('id', 'country')->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);
        $id = (int) $row['id'];

        try {
            self::assertSame('PT', $profiles->update($id, ['country' => 'PT'])->assertOk()->record()['country']);

            foreach (['XX', 'pt', 'Portugal', 'PRT', 351] as $code) {
                $profiles->update($id, ['country' => $code])->assertInvalid('country', 'invalid_choice');
            }

            self::assertSame('PT', $this->shop()->table('customer_profiles')->select('country')->where('id', '=', $id)->value('country'), 'nothing else was written');
        } finally {
            $this->shop()->table('customer_profiles')->where('id', '=', $id)->update(['country' => $row['country']]);
        }

        $teams = $this->vector($this->realAdminHeaders())->resource('support-teams');
        $team = $this->support()->table('support_teams')->select('id', 'countries')->orderBy('id', 'ASC')->first();
        self::assertIsArray($team);
        $teamId = (int) $team['id'];

        try {
            self::assertSame(['DE', 'FR'], $teams->update($teamId, ['countries' => ['FR', 'DE', 'FR']])->assertOk()->record()['countries'], 'in the order of the code list');
            $refused = $teams->update($teamId, ['countries' => ['DE', 'ZZ']])->assertStatus(422)->json();
            self::assertSame([['countries', '1'], 'invalid_choice'], [$refused['errors'][0]['path'], $refused['errors'][0]['code']]);
        } finally {
            $this->support()->table('support_teams')->where('id', '=', $teamId)->update(['countries' => $team['countries']]);
        }
    }

    public function testCountriesFilterLikeASelect(): void
    {
        $h = $this->adminHeaders();
        $german = array_map('intval', array_column($this->shop()->table('customer_profiles')->select('id')->where('country', '=', 'DE')->orderBy('id', 'ASC')->get(), 'id'));
        self::assertNotSame([], $german);
        $listed = $this->client->get('/vector/r/customer-profiles', query: ['filter' => ['country' => 'DE'], 'perPage' => 100, 'sort' => 'id'], headers: $h)->assertOk()->json();
        self::assertSame($german, array_map(static fn (array $r): int => (int) $r['id'], $listed['data']));
        $this->client->get('/vector/r/customer-profiles', query: ['filter' => ['country' => 'XX']], headers: $h)->assertStatus(422);

        $serving = fn (array $query): array => array_column($this->client->get('/vector/r/support-teams', query: ['filter' => ['countries' => $query], 'sort' => 'name'], headers: $h)->assertOk()->json()['data'], 'name');
        self::assertSame(['Billing', 'Shipping'], $serving(['any' => 'DE']));
        self::assertSame(['Billing', 'Returns', 'Shipping'], $serving(['any' => 'AT,IE,NO']));
        self::assertSame(['Shipping'], $serving(['all' => 'NL,PT']));
        self::assertSame(['Technical'], $serving(['null' => '1']), 'a team serving everywhere stores none');
    }

    public function testOpenApiAndMcpDescribeACountry(): void
    {
        $document = $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($document);
        $country = null;
        $countries = null;

        foreach ($document['components']['schemas'] as $schema) {
            $country ??= isset($schema['properties']['country']['enum']) ? $schema['properties']['country'] : null;
            $countries ??= isset($schema['properties']['countries']['items']) ? $schema['properties']['countries'] : null;
        }

        self::assertIsArray($country);
        self::assertSame(Countries::codes(), $country['enum']);
        self::assertSame('An ISO 3166-1 alpha-2 country code.', $country['description']);
        self::assertIsArray($countries);
        self::assertSame([['array', 'null'], Countries::codes(), true], [$countries['type'], $countries['items']['enum'], $countries['uniqueItems']]);

        $profile = array_column($this->mcpTool('vector_describe', ['resource' => 'customer-profiles'])['fields'], null, 'name')['country'];
        self::assertSame(['country', 'an ISO 3166-1 alpha-2 country code in upper case', ['eq', 'ne', 'in', 'nin']], [$profile['type'], $profile['format'], $profile['filter']]);
        self::assertStringStartsWith(count(Countries::NAMES) . ' values, such as AD, AE', $profile['choices']);

        $team = array_column($this->mcpTool('vector_describe', ['resource' => 'support-teams'])['fields'], null, 'name')['countries'];
        self::assertSame(['country', true, ['any', 'all', 'null', 'nnull']], [$team['type'], $team['multiple'], $team['filter']]);
        self::assertStringContainsString('a list of ISO 3166-1 alpha-2 country codes', $team['format']);
    }

    /** @return array<string, mixed> */
    private function compile(VectorResource $resource): array
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));

        return (new ResourceCompiler($metadata, [$resource::class]))->compile()['country-profiles'];
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
