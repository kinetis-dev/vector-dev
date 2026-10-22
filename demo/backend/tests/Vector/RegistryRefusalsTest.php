<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Auth\DemoPasswordHasher;
use App\Domain\Category;
use App\Domain\Customer;
use App\Domain\CustomerProfile;
use App\Domain\Order;
use App\Domain\OrderLine;
use App\Domain\Product;
use App\Domain\Tag;
use App\Domain\User;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Vector\Actions\ActionRequest;
use Kinetis\Vector\Actions\ActionResponse;
use Kinetis\Vector\Actions\VectorAction;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\BelongsTo;
use Kinetis\Vector\Fields\Currency;
use Kinetis\Vector\Fields\HasMany;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Money;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Fields\Password;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Registry\LayoutCompiler;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Registry\WidgetCompiler;
use Kinetis\Vector\VectorResource;
use Kinetis\Vector\Widgets\VectorWidget;
use Kinetis\Vector\Widgets\WidgetRequest;
use Kinetis\Vector\Widgets\WidgetResult;
use PHPUnit\Framework\TestCase;

/**
 * The refusals of the registry compilers that no other test names in
 * full, with no database: each definition below is wrong in one way, and
 * the build refuses it with exactly this message.
 */
final class RegistryRefusalsTest extends TestCase
{
    private const array ENTITIES = [Category::class, Customer::class, CustomerProfile::class, Order::class, OrderLine::class, Product::class, Tag::class, User::class];

    private const string OWNER = 'App\Vector\GadgetResource';

    public function testAResourceClassMustCarryTheAttributeExtendVectorResourceAndMapAnEntity(): void
    {
        $bare = new class extends VectorResource {};
        $plain = new #[Resource(Tag::class, slug: 'plain-tags')] class {};
        $unmapped = new #[Resource(\stdClass::class, slug: 'unmapped')] class extends VectorResource {};

        self::assertSame('Resource ' . $bare::class . ': carries no #[Resource] attribute', self::refusal(static fn () => new ResourceCompiler(self::metadata(), [$bare::class])));
        self::assertSame('Resource ' . $plain::class . ': does not extend ' . VectorResource::class, self::refusal(static fn () => new ResourceCompiler(self::metadata(), [$plain::class])));
        self::assertSame('Resource ' . $unmapped::class . ': stdClass is not a mapped entity', self::refusal(static fn () => new ResourceCompiler(self::metadata(), [$unmapped::class])));
        // a class that neither carries the attribute nor extends VectorResource is refused for the attribute first
        self::assertSame('Resource stdClass: carries no #[Resource] attribute', self::refusal(static fn () => new ResourceCompiler(self::metadata(), [\stdClass::class])));
    }

    public function testTheIdentifierAndTheTitleMustBeAdministeredColumns(): void
    {
        $anonymous = new #[Resource(Tag::class, slug: 'anonymous-tags')] class extends VectorResource {
            public function fields(): array
            {
                return [Text::make('name')];
            }
        };
        $ghost = new #[Resource(Tag::class, slug: 'ghost-tags', title: 'ghost')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')];
            }
        };

        self::assertSame('Resource ' . $anonymous::class . ': the identifier field "id" must be administered', self::refusal(static fn () => self::compile($anonymous)));
        self::assertSame('Resource ' . $ghost::class . ': title field "ghost" maps no column', self::refusal(static fn () => self::compile($ghost)));
    }

    public function testEachFieldIsDeclaredOnceAndAsWhatItMaps(): void
    {
        $twice = new #[Resource(Tag::class, slug: 'twice-tags')] class extends VectorResource {
            public function fields(): array
            {
                return [Text::make('name'), Text::make('name')];
            }
        };
        $relation = new #[Resource(Order::class, slug: 'text-customer-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('customer')];
            }
        };

        // the duplicate is refused before the missing identifier: fields are compiled first
        self::assertSame(self::fieldRefusal($twice, 'name', 'is declared twice'), self::refusal(static fn () => self::compile($twice)));
        self::assertSame(self::fieldRefusal($relation, 'customer', 'is a #[BelongsTo] property; declare it with BelongsTo::to()'), self::refusal(static fn () => self::compile($relation)));
    }

    public function testACollectionIsNeitherSortedNorFiltered(): void
    {
        $sorted = new #[Resource(Customer::class, slug: 'sorted-customers')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), HasMany::to('orders', RefusalOrders::class)->sortable()];
            }
        };
        $filtered = new #[Resource(Customer::class, slug: 'filtered-customers')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), HasMany::to('orders', RefusalOrders::class)->filterable()];
            }
        };

        self::assertSame(self::fieldRefusal($sorted, 'orders', 'maps no column and cannot be sortable'), self::refusal(static fn () => self::compile($sorted)));
        self::assertSame(self::fieldRefusal($filtered, 'orders', 'is a collection and cannot be filterable'), self::refusal(static fn () => self::compile($filtered)));
    }

    public function testACurrencyCodeAndAPasswordHashAreStoredInStringProperties(): void
    {
        $currency = new #[Resource(Order::class, slug: 'int-currency-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Currency::make('totalCents')];
            }
        };
        $password = new #[Resource(User::class, slug: 'bool-password-users')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Password::make('isAdmin')->hashed(DemoPasswordHasher::class)];
            }
        };

        self::assertSame(self::fieldRefusal($currency, 'totalCents', 'is a currency field, which stores its code in a string property'), self::refusal(static fn () => self::compile($currency)));
        self::assertSame(self::fieldRefusal($password, 'isAdmin', 'is a hashed password, which stores its hash in a string property'), self::refusal(static fn () => self::compile($password)));
    }

    public function testAMoneyFieldsCurrencyIsAColumnOrStartsWithABelongsTo(): void
    {
        $dotted = new #[Resource(Order::class, slug: 'dotted-currency-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('notes'), Money::make('totalCents')->currencyField('notes.code')];
            }
        };
        $unknown = new #[Resource(Order::class, slug: 'unknown-currency-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Money::make('totalCents')->currencyField('ghost')];
            }
        };

        self::assertSame(self::fieldRefusal($dotted, 'totalCents', 'currencyField "notes.code" does not start with a belongsTo field'), self::refusal(static fn () => self::compile($dotted)));
        self::assertSame(self::fieldRefusal($unknown, 'totalCents', 'currencyField "ghost" is not a column-mapped field'), self::refusal(static fn () => self::compile($unknown)));
    }

    public function testAFieldIsNotConditionalOnItself(): void
    {
        $itself = new #[Resource(Tag::class, slug: 'self-conditional-tags')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')->visibleWhen('name', 'eq', 'x')];
            }
        };

        self::assertSame(self::fieldRefusal($itself, 'name', 'has a visible condition on itself'), self::refusal(static fn () => self::compile($itself)));
    }

    public function testARepeaterTakesNoCondition(): void
    {
        $conditional = new #[Resource(Order::class, slug: 'conditional-repeater-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('number'), HasMany::to('lines', RefusalOrderLines::class)->repeater()->visibleWhen('number', 'nnull')];
            }
        };

        self::assertSame(self::fieldRefusal($conditional, 'lines', 'is a repeater, which takes no visibleWhen, requiredWhen or disabledWhen'), self::refusal(static fn () => self::compile($conditional)));
    }

    public function testActionsAreVectorActionsWithDistinctNamesAndFields(): void
    {
        $foreign = new #[Resource(Tag::class, slug: 'foreign-action-tags')] class extends VectorResource {
            public function actions(): array
            {
                /** @phpstan-ignore return.type (a mistaken resource naming a class that is no action) */
                return [\stdClass::class];
            }
        };
        $same = new #[Resource(Tag::class, slug: 'same-action-tags')] class extends VectorResource {
            public function actions(): array
            {
                return [RefusalAction::class, RefusalAction::class];
            }
        };
        $fields = new #[Resource(Tag::class, slug: 'twice-field-action-tags')] class extends VectorResource {
            public function actions(): array
            {
                return [RefusalTwiceFieldAction::class];
            }
        };

        self::assertSame('Resource ' . $foreign::class . ': actions() names stdClass, which is not a VectorAction', self::refusal(static fn () => self::compile($foreign)));
        self::assertSame('Resource ' . $same::class . ': two actions are named "refusal"', self::refusal(static fn () => self::compile($same)));
        self::assertSame('Resource ' . RefusalTwiceFieldAction::class . ', field "note": is declared twice', self::refusal(static fn () => self::compile($fields)));
    }

    public function testWidgetsAreVectorWidgetsOfAKnownTypeWithDistinctNames(): void
    {
        $owner = 'Resource ' . self::OWNER . ': ';

        /** @phpstan-ignore argument.type (a mistaken resource naming a class that is no widget) */
        self::assertSame($owner . 'widgets() names stdClass, which is not a VectorWidget', self::refusal(static fn () => WidgetCompiler::widgets([\stdClass::class], self::OWNER)));
        self::assertSame($owner . 'widget ' . RefusalPieWidget::class . ' has the type "pie"', self::refusal(static fn () => WidgetCompiler::widgets([RefusalPieWidget::class], self::OWNER)));
        self::assertSame($owner . 'two widgets are named "refusal"', self::refusal(static fn () => WidgetCompiler::widgets([RefusalWidget::class, RefusalWidget::class], self::OWNER)));
    }

    public function testATopLevelItemIsAFieldASectionOrTabs(): void
    {
        self::assertSame(self::OWNER . '::fields() names "name" at the top level; a field name belongs inside a section or a tab.', self::refusal(static fn () => LayoutCompiler::split(['name'], self::OWNER)));
        self::assertSame(self::OWNER . '::fields() returns int; it returns fields, sections and tabs.', self::refusal(static fn () => LayoutCompiler::split([42], self::OWNER)));
    }

    private static function metadata(): MetadataRegistry
    {
        return MetadataRegistry::fromClasses(self::ENTITIES);
    }

    /** @return array<string, array<string, mixed>> */
    private static function compile(VectorResource $resource): array
    {
        return (new ResourceCompiler(self::metadata(), [$resource::class, RefusalOrders::class, RefusalOrderLines::class]))->compile();
    }

    private static function refusal(callable $compile): string
    {
        try {
            $compile();
        } catch (ResourceCompileException $e) {
            return $e->getMessage();
        }

        self::fail('The compiler did not refuse.');
    }

    private static function fieldRefusal(VectorResource $resource, string $field, string $reason): string
    {
        return 'Resource ' . $resource::class . ", field \"{$field}\": {$reason}";
    }
}

#[Resource(Order::class, slug: 'refusal-orders', relationTarget: true)]
final class RefusalOrders extends VectorResource {}

#[Resource(OrderLine::class, slug: 'refusal-order-lines', relationTarget: true)]
final class RefusalOrderLines extends VectorResource
{
    public function fields(): array
    {
        return [Id::make(), BelongsTo::to('order', RefusalOrders::class), Number::make('quantity'), Number::make('unitPriceCents')];
    }
}

final class RefusalAction extends VectorAction
{
    public function name(): string
    {
        return 'refusal';
    }

    public function handle(ActionRequest $request): ActionResponse
    {
        return ActionResponse::message('Done.');
    }
}

final class RefusalTwiceFieldAction extends VectorAction
{
    public function fields(): array
    {
        return [Text::make('note'), Text::make('note')];
    }

    public function handle(ActionRequest $request): ActionResponse
    {
        return ActionResponse::message('Done.');
    }
}

class RefusalWidget extends VectorWidget
{
    public function name(): string
    {
        return 'refusal';
    }

    public function type(): string
    {
        return self::STAT;
    }

    public function calculate(WidgetRequest $request): WidgetResult
    {
        throw new \LogicException('not computed here');
    }
}

final class RefusalPieWidget extends RefusalWidget
{
    public function type(): string
    {
        return 'pie';
    }
}
