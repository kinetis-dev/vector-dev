<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Product;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\DateTime;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Layout\Section;
use Kinetis\Vector\Fields\Layout\Tabs;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\VectorResource;

/**
 * Sections, tabs and column spans: compiled beside the flat field list,
 * published per view, and pruned with the fields a user may not see.
 */
final class FormLayoutTest extends VectorTestCase
{
    private LayoutHidingPolicy $policy;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->policy = new LayoutHidingPolicy();
        $app->instance(AllowAllPolicy::class, $this->policy);
    }

    public function testTheProductsDescriptorCarriesItsLayoutPerView(): void
    {
        $products = $this->client->get('/vector/schema/products', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($products);

        // the field list is untouched: the layout places fields by name
        self::assertSame(
            ['id', 'sku', 'name', 'slug', 'status', 'category', 'priceCents', 'currency', 'stock', 'weightKg', 'isFeatured', 'description', 'attributes', 'imageKey', 'tags', 'releasedAt', 'createdAt', 'updatedAt'],
            array_column($products['fields'], 'name'),
        );

        self::assertSame([
            'section Details: sku name slug status category',
            'section Pricing and stock: priceCents currency stock weightKg',
            'tabs: [Content: description attributes imageKey] [Publishing: isFeatured releasedAt tags]',
        ], self::outline($products['layout']['form']));

        // the show page keeps what the form has not: the key and the timestamps
        self::assertSame([
            'field id',
            'section Details: sku name slug status category',
            'section Pricing and stock: priceCents currency stock weightKg',
            'tabs: [Content: description attributes imageKey] [Publishing: isFeatured releasedAt tags]',
            'field createdAt',
            'field updatedAt',
        ], self::outline($products['layout']['show']));

        $pricing = $products['layout']['form'][1];
        self::assertSame(['section', 'Pricing and stock', "Prices are in the product's own currency.", true, false, 2], [$pricing['kind'], $pricing['label'], $pricing['description'], $pricing['collapsible'], $pricing['collapsed'], $pricing['columns']]);
        self::assertSame('full', $products['layout']['form'][2]['children'][0]['children'][0]['span'], 'description spans the row');
        self::assertNull($products['layout']['form'][0]['children'][0]['span']);
    }

    public function testASectionInsideATabAndACollapsedOne(): void
    {
        $tickets = $this->client->get('/vector/schema/tickets', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($tickets);
        $handling = $tickets['layout']['form'][0]['children'][2];

        self::assertSame(['tab', 'Handling'], [$handling['kind'], $handling['label']]);
        self::assertSame(['Assignment', 'Schedule', 'Compensation'], array_column($handling['children'], 'label'));
        self::assertSame([true, true], [$handling['children'][2]['collapsible'], $handling['children'][2]['collapsed']]);
    }

    public function testAResourceWithoutLayoutPublishesNull(): void
    {
        $this->client->get('/vector/schema/tags', headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('layout', null);
    }

    public function testAHiddenFieldLeavesTheLayoutAndTakesAnEmptiedSectionOrTabWithIt(): void
    {
        $this->policy->hidden = ['priceCents', 'currency', 'stock', 'weightKg', 'isFeatured', 'releasedAt', 'tags', 'slug'];

        $products = $this->client->get('/vector/schema/products', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($products);

        self::assertSame([
            'section Details: sku name status category',
            'tabs: [Content: description attributes imageKey]',
        ], self::outline($products['layout']['form']));
    }

    public function testATopLevelFieldKeepsItsPlaceAndANamedOneMoves(): void
    {
        $layout = self::compileWith(new #[Resource(Product::class, slug: 'layout-products')] class extends VectorResource {
            public function fields(): array
            {
                return [
            Id::make(),
            Text::make('sku'),
            Section::make('Names')->fields(['name']),
            Text::make('name'),
            Number::make('stock')->columnSpan(2),
        ];
            }
        });

        // the key is never on a form, only on the show page
        self::assertSame(['field sku', 'section Names: name', 'field stock'], self::outline($layout['form'] ?? []));
        self::assertSame(['field id', 'field sku', 'section Names: name', 'field stock'], self::outline($layout['show'] ?? []));
        self::assertSame(2, ($layout['form'] ?? [])[2]['span']);
    }

    public function testAColumnSpanAloneGivesALayout(): void
    {
        $layout = self::compileWith(new #[Resource(Product::class, slug: 'layout-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')->columnSpan('full')];
            }
        });

        self::assertSame(['field name'], self::outline($layout['form'] ?? []));

        self::assertNull(self::compileWith(new #[Resource(Product::class, slug: 'layout-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')];
            }
        }));
    }

    public function testAFieldObjectInsideASectionIsAFieldOfTheResource(): void
    {
        $layout = self::compileWith(new #[Resource(Product::class, slug: 'layout-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Tabs::make()->tab('Main', [Section::make('Codes')->fields([Text::make('sku')]), Text::make('name')])];
            }
        });

        self::assertSame(['tabs: [Main: section Codes: sku name]'], self::outline($layout['form'] ?? []));
    }

    public function testAnUnknownFieldNameIsRefused(): void
    {
        $this->expectException(ResourceCompileException::class);
        $this->expectExceptionMessage('is placed in the layout but is not a field of the resource');

        self::compileWith(new #[Resource(Product::class, slug: 'layout-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Section::make('Names')->fields(['nickname'])];
            }
        });
    }

    public function testAFieldPlacedTwiceIsRefused(): void
    {
        $this->expectException(ResourceCompileException::class);
        $this->expectExceptionMessage('is placed in the layout twice');

        self::compileWith(new #[Resource(Product::class, slug: 'layout-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), Section::make('A')->fields(['name']), Section::make('B')->fields(['name'])];
            }
        });
    }

    public function testAFieldNameAtTheTopLevelIsRefused(): void
    {
        $this->expectException(ResourceCompileException::class);
        $this->expectExceptionMessage('a field name belongs inside a section or a tab');

        self::compileWith(new #[Resource(Product::class, slug: 'layout-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), 'name']; // @phpstan-ignore return.type (the refusal is the point)
            }
        });
    }

    public function testASectionHoldsNoLayoutAndTabsNeedATab(): void
    {
        try {
            Section::make('Outer')->fields([Section::make('Inner')]); // @phpstan-ignore argument.type (the refusal is the point)
            self::fail('a section inside a section was accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('a child is a field, a field name.', $e->getMessage());
        }

        $this->expectException(ResourceCompileException::class);
        $this->expectExceptionMessage('declares a Tabs with no tab');
        self::compileWith(new #[Resource(Product::class, slug: 'layout-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Tabs::make()];
            }
        });
    }

    public function testColumnsAndSpanAreBounded(): void
    {
        try {
            Section::make('Wide')->columns(5);
            self::fail('five columns were accepted');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        Text::make('name')->columnSpan(3);
    }

    /**
     * The layout of a throwaway products resource, compiled.
     *
     * @return array{form: list<array<string, mixed>>, show: list<array<string, mixed>>}|null
     */
    private static function compileWith(VectorResource $resource): ?array
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));

        return (new ResourceCompiler($metadata, [$resource::class]))->compile()['layout-products']['layout'];
    }

    /**
     * One line per node: `section Label: a b`, `tabs: [Tab: a b]`, `field a`.
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<string>
     */
    private static function outline(array $nodes): array
    {
        $line = static function (array $node) use (&$line): string {
            $children = static fn (array $n): string => implode(' ', array_map(
                static fn (array $c): string => $c['kind'] === 'field' ? $c['name'] : $line($c),
                $n['children'],
            ));

            return match ($node['kind']) {
                'field' => "field {$node['name']}",
                'section' => "section {$node['label']}: {$children($node)}",
                'tab' => "{$node['label']}: {$children($node)}",
                'tabs' => 'tabs: ' . implode(' ', array_map(static fn (array $tab): string => '[' . $line($tab) . ']', $node['children'])),
                default => throw new \LogicException("unknown node kind {$node['kind']}"),
            };
        };

        return array_map($line, $nodes);
    }
}

final class LayoutHidingPolicy extends ResourcePolicy
{
    /** @var list<string> */
    public array $hidden = [];

    public function viewField(CurrentUserInterface $user, string $field): bool
    {
        return !in_array($field, $this->hidden, true);
    }
}
