<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Product;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\Boolean;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\VectorResource;
use Kinetis\Vector\Write\FieldInput;

/**
 * visibleWhen / requiredWhen / disabledWhen: published in the descriptor
 * for the form, and enforced by the server the same way. The demo's
 * products hide their release date while a draft, require it once
 * active, and lock "featured" while archived.
 */
final class FieldConditionsTest extends VectorTestCase
{
    public function testConditionsArePublishedWithTheField(): void
    {
        $schema = $this->client->get('/vector/schema/products', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($schema);
        $fields = array_column($schema['fields'], null, 'name');
        self::assertSame([['field' => 'status', 'op' => 'ne', 'value' => 'draft']], $fields['releasedAt']['conditions']['visible']);
        self::assertSame([['field' => 'status', 'op' => 'eq', 'value' => 'active']], $fields['releasedAt']['conditions']['required']);
        self::assertSame([['field' => 'status', 'op' => 'eq', 'value' => 'archived']], $fields['isFeatured']['conditions']['disabled']);
        self::assertSame(['visible' => [], 'required' => [], 'disabled' => []], $fields['name']['conditions']);
    }

    public function testTheServerAppliesTheSameConditionsOnCreate(): void
    {
        $h = $this->adminHeaders();
        $sku = 'COND-' . bin2hex(random_bytes(3));
        $base = ['sku' => $sku, 'name' => 'Conditioned', 'slug' => 'conditioned-' . strtolower($sku), 'category' => $this->categoryId(),
            'priceCents' => 100, 'currency' => 'EUR', 'stock' => 1, 'weightKg' => 1, 'isFeatured' => false];

        try {
            // active: the release date is required
            $this->client->post('/vector/r/products', body: [...$base, 'status' => 'active'], headers: $h)->assertValidationError('releasedAt');

            // draft: the release date is hidden, so a value sent anyway is not written
            $created = $this->client->post('/vector/r/products', body: [...$base, 'status' => 'draft', 'releasedAt' => '2026-01-01T00:00:00Z'], headers: $h)
                ->assertCreated()->json();
            self::assertIsArray($created);
            self::assertNull($created['data']['releasedAt']);
        } finally {
            $this->shop()->table('products')->where('sku', '=', $sku)->delete();
        }
    }

    public function testTheServerAppliesTheSameConditionsOnUpdateAgainstTheStoredRecord(): void
    {
        $h = $this->realAdminHeaders();
        $archived = $this->product('archived');
        $active = $this->product('active');

        try {
            // archived: "featured" is locked, whatever the request says
            $this->client->patch("/vector/r/products/{$archived['id']}", body: ['isFeatured' => !$archived['featured']], headers: $h)
                ->assertOk()->assertJsonPath('data.isFeatured', $archived['featured']);

            // the stored status is active, so clearing the release date is refused
            $this->client->patch("/vector/r/products/{$active['id']}", body: ['releasedAt' => null], headers: $h)->assertValidationError('releasedAt');

            // moving to draft in the same request hides the date: its value is ignored, not required
            $this->client->patch("/vector/r/products/{$active['id']}", body: ['status' => 'draft', 'releasedAt' => null], headers: $h)
                ->assertOk()->assertJsonPath('data.status', 'draft');
        } finally {
            $this->shop()->table('products')->where('id', '=', $active['id'])->update(['status' => 'active']);
        }
    }

    public function testActionAndSettingsInputsHonourConditionsToo(): void
    {
        $fields = [];

        foreach ([
            Boolean::make('notify'),
            Text::make('email')->visibleWhen('notify', 'eq', true)->requiredWhen('notify', 'eq', true),
            Number::make('copies')->disabledWhen('notify', 'eq', false)->default(1),
        ] as $field) {
            $fields[$field->name()] = ResourceCompiler::compileLoose($field, [], 'test');
        }

        $app = $this->app;
        self::assertInstanceOf(\Kinetis\Container\AppScope::class, $app);
        $input = $app->createRequestScope()->get(FieldInput::class);
        self::assertInstanceOf(FieldInput::class, $input);

        $quiet = $input->validate($fields, ['notify' => false, 'email' => 'ignored@example.com', 'copies' => 5]);
        self::assertNull($quiet['email'], 'hidden: the value sent is not taken');
        self::assertSame(1, $quiet['copies'], 'disabled: the default stands');

        try {
            $input->validate($fields, ['notify' => true]);
            self::fail('email is required while notify is on');
        } catch (\Kinetis\Validation\Exception\ValidationException $e) {
            self::assertSame([['email']], array_map(static fn ($v): array => $v->path, $e->violations));
        }
    }

    public function testAConditionOnAFieldTheFormDoesNotHaveFailsTheBuild(): void
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));
        $bad = new #[Resource(Product::class, slug: 'bad-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')->visibleWhen('nothing', 'eq', 1)];
            }
        };

        $this->expectException(ResourceCompileException::class);
        $this->expectExceptionMessage('has a visible condition on "nothing", which is not a field of the same form');
        (new ResourceCompiler($metadata, [$bad::class]))->compile();
    }

    public function testAMalformedConditionIsRefusedWhereItIsDeclared(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Text::make('name')->visibleWhen('status', 'in', 'active');
    }

    /** @return array{id: int, featured: bool} */
    private function product(string $status): array
    {
        $query = $this->shop()->table('products')->select('id', 'is_featured')->where('status', '=', $status)->where('deleted_at', '=', null);

        if ($status === 'active') {
            $query->where('released_at', '!=', null);
        }

        $row = $query->orderBy('id', 'ASC')->first();
        self::assertIsArray($row, "a seeded {$status} product");

        return ['id' => (int) $row['id'], 'featured' => (bool) $row['is_featured']];
    }

    private function categoryId(): int
    {
        return (int) $this->shop()->table('categories')->select('id')->orderBy('id', 'ASC')->value('id');
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}
