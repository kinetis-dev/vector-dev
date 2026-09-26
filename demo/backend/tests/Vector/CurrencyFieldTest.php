<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Product;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Config\Config;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\Currency;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Testing\InteractsWithVector;
use Kinetis\Vector\VectorDefaults;
use Kinetis\Vector\VectorResource;

/**
 * A currency field: an ISO 4217 code chosen from a list, stored
 * upper-case, starting from its own default or the application's
 * VECTOR_CURRENCY.
 */
final class CurrencyFieldTest extends VectorTestCase
{
    use InteractsWithVector;

    public function testTheChoicesAndTheDefaultArePublished(): void
    {
        $orders = array_column($this->vector($this->adminHeaders())->schema('orders')->assertOk()->json()['fields'], null, 'name');
        self::assertSame('currency', $orders['currency']['type']);
        self::assertSame(['EUR', 'USD', 'GBP'], array_column($orders['currency']['options'], 'value'));
        self::assertSame([true, 'EUR'], [$orders['currency']['hasDefault'], $orders['currency']['default']], 'VECTOR_CURRENCY fills in a default the field does not set');
        self::assertSame(['eq', 'ne', 'in', 'nin'], $orders['currency']['operators']);

        // money values are minor units: the schema says which codes are not hundredths
        $schema = $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($schema);
        self::assertSame([0, 3], [$schema['minorUnits']['JPY'], $schema['minorUnits']['BHD']]);
        self::assertArrayNotHasKey('EUR', $schema['minorUnits']);
        self::assertSame([2, 0, 3], [Currency::minorUnits('eur'), Currency::minorUnits('JPY'), Currency::minorUnits('KWD')]);

        $tickets = array_column($this->vector($this->adminHeaders())->schema('tickets')->assertOk()->json()['fields'], null, 'name');
        self::assertContains('JPY', array_column($tickets['currency']['options'], 'value'), 'without allowed(), every ISO 4217 code');
    }

    public function testAWrittenCodeIsOneOfTheChoicesInUpperCase(): void
    {
        $products = $this->vector($this->realAdminHeaders())->resource('products');
        $id = (int) $this->shop()->table('products')->select('id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->value('id');

        try {
            $products->update($id, ['currency' => ' usd '])->assertOk()->assertRecord(['currency' => 'USD']);
            $products->update($id, ['currency' => 'JPY'])->assertInvalid('currency', 'invalid_choice');
            $products->update($id, ['currency' => 'EURO'])->assertInvalid('currency', 'invalid_choice');
            $products->list(filter: ['currency' => ['in' => 'usd,GBP']], perPage: 100)->assertListed($id);
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['currency' => 'EUR']);
        }
    }

    public function testACreateThatLeavesItOutGetsTheDefault(): void
    {
        $orders = $this->vector($this->realAdminHeaders())->resource('orders');
        $id = $orders->create([
            'number' => 'ORD-CUR-' . bin2hex(random_bytes(3)),
            'customer' => (int) $this->shop()->table('customers')->select('id')->orderBy('id', 'ASC')->value('id'),
            'status' => 'pending',
            'placedAt' => '2026-09-26T09:00:00Z',
            'lines' => [['product' => (int) $this->shop()->table('products')->select('id')->orderBy('id', 'ASC')->value('id'), 'quantity' => 1, 'unitPriceCents' => 100]],
        ])->assertCreated()->assertRecord(['currency' => 'EUR'])->id();

        $orders->batch('delete', [$id])->assertOk();
    }

    public function testTheCodesAndTheDefaultAreChecked(): void
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));
        $unknown = new #[Resource(Product::class, slug: 'currency-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Currency::make('currency')->allowed(['EUR', 'ABC'])];
            }
        };
        $default = new #[Resource(Product::class, slug: 'currency-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Currency::make('currency')->allowed(['EUR', 'USD'])->default('GBP')];
            }
        };

        foreach ([[$unknown, 'which ISO 4217 does not name'], [$default, 'defaults to a currency it does not allow']] as [$resource, $message]) {
            try {
                (new ResourceCompiler($metadata, [$resource::class]))->compile();
                self::fail("compiled: {$message}");
            } catch (ResourceCompileException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }

        self::assertSame('USD', VectorDefaults::fromConfig(new Config(['VECTOR_CURRENCY' => 'usd']))->currency);
        $this->expectException(\InvalidArgumentException::class);
        VectorDefaults::fromConfig(new Config(['VECTOR_CURRENCY' => 'EURO']));
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}
