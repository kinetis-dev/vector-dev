<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Auth\DemoPasswordHasher;
use App\Domain\Category;
use App\Domain\Customer;
use App\Domain\CustomerProfile;
use App\Domain\Order;
use App\Domain\OrderLine;
use App\Domain\OrderStatus;
use App\Domain\Product;
use App\Domain\Tag;
use App\Domain\User;
use App\Tests\Vector\Fixtures\Level;
use App\Vector\TagResource;
use Kinetis\Orm\Attributes\BelongsTo as OrmBelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany as OrmHasMany;
use Kinetis\Orm\Attributes\Id as EntityId;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Vector\Actions\ActionRequest;
use Kinetis\Vector\Actions\ActionResponse;
use Kinetis\Vector\Actions\VectorAction;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Auth\PasswordHasherInterface;
use Kinetis\Vector\Badge;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\BelongsTo;
use Kinetis\Vector\Fields\Boolean;
use Kinetis\Vector\Fields\BooleanGroup;
use Kinetis\Vector\Fields\Color;
use Kinetis\Vector\Fields\Computed;
use Kinetis\Vector\Fields\Country;
use Kinetis\Vector\Fields\Currency;
use Kinetis\Vector\Fields\Date;
use Kinetis\Vector\Fields\DateTime;
use Kinetis\Vector\Fields\Email;
use Kinetis\Vector\Fields\HasMany;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Image;
use Kinetis\Vector\Fields\Json;
use Kinetis\Vector\Fields\KeyValue;
use Kinetis\Vector\Fields\ManyToMany;
use Kinetis\Vector\Fields\Money;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Fields\Password;
use Kinetis\Vector\Fields\Repeater;
use Kinetis\Vector\Fields\RichText;
use Kinetis\Vector\Fields\Select;
use Kinetis\Vector\Fields\Tag as TagField;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Fields\Textarea;
use Kinetis\Vector\FullText;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\VectorResource;
use PHPUnit\Framework\TestCase;

/**
 * The resource compiler on its own, with no database: small resources
 * over the demo's entities, compiled together with the fixtures at the
 * end of this file, and the exact descriptor or refusal they produce.
 */
final class ResourceCompilerTest extends TestCase
{
    /** Compiled with every resource under test: the relation targets the fixtures name. */
    private const array FIXTURES = [CompilerCustomers::class, CompilerOrders::class, CompilerOrderLines::class, CompilerProducts::class, CompilerCategories::class];

    /** The shop's entities, every relationship target among them, and the board fixtures at the end of this file. */
    private const array ENTITIES = [Category::class, Customer::class, CustomerProfile::class, Order::class, OrderLine::class, Product::class, Tag::class, User::class, CompilerBoard::class, CompilerCard::class, CompilerNote::class];

    public function testEachFieldTypeFiltersWithTheOperatorsOfItsShape(): void
    {
        $shapes = [];

        foreach (['id', 'number', 'money', 'boolean', 'date', 'datetime', 'select', 'country', 'currency', 'booleanGroup', 'tag', 'belongsTo', 'richtext', 'hasOne', 'hasMany', 'manyToMany', 'repeater', 'string', 'email'] as $type) {
            $shapes[$type] = ResourceCompiler::operatorShape($type);
        }

        self::assertSame([
            'id' => 'id',
            'number' => 'number',
            'money' => 'number',
            'boolean' => 'boolean',
            'date' => 'datetime',
            'datetime' => 'datetime',
            'select' => 'enum',
            'country' => 'enum',
            'currency' => 'enum',
            'booleanGroup' => 'list',
            'tag' => 'list',
            'belongsTo' => 'belongsTo',
            'richtext' => 'presence',
            'hasOne' => null,
            'hasMany' => null,
            'manyToMany' => null,
            'repeater' => null,
            'string' => 'string',
            'email' => 'string',
        ], $shapes);

        self::assertSame(['eq', 'ne', 'like', 'nlike', 'in', 'nin'], ResourceCompiler::operatorsFor(['type' => 'string', 'filterable' => true]), 'a field that states no nullability takes no null operators');
        self::assertSame(['eq', 'null', 'nnull'], ResourceCompiler::operatorsFor(['type' => 'boolean', 'filterable' => true, 'nullable' => true]));
        self::assertSame([], ResourceCompiler::operatorsFor(['type' => 'string', 'filterable' => false, 'nullable' => true]), 'a field that is not filterable takes no operator');
        self::assertSame([], ResourceCompiler::operatorsFor(['type' => 'hasMany', 'filterable' => true]));
    }

    public function testADependentChoiceListNamesAnotherFieldAndCarriesItsLabel(): void
    {
        $fields = [
            'team' => ['type' => 'belongsTo', 'label' => 'Support team'],
            'assignee' => ['type' => 'belongsTo', 'label' => 'Assignee', 'relation' => ['dependsOn' => ['field' => 'team', 'on' => 'team']]],
        ];

        $checked = ResourceCompiler::checkDependencies($fields, 'Owner');
        self::assertSame(['field' => 'team', 'on' => 'team', 'label' => 'Support team'], $checked['assignee']['relation']['dependsOn']);

        $this->expectException(ResourceCompileException::class);
        $this->expectExceptionMessage('Resource Owner, field "team": depends on team, which is not another field of the form');
        ResourceCompiler::checkDependencies(['team' => ['type' => 'belongsTo', 'label' => 'Team', 'relation' => ['dependsOn' => ['field' => 'team', 'on' => 'team']]]], 'Owner');
    }

    public function testALooseFieldTakesTheValueTypeOfItsFieldType(): void
    {
        $valueTypes = [];

        foreach ([
            Number::make('count'),
            Number::make('whole')->decimals(0),
            Number::make('ratio')->decimals(2),
            Money::make('amount'),
            Boolean::make('notify'),
            Date::make('day'),
            DateTime::make('at'),
            Text::make('note'),
        ] as $field) {
            $valueTypes[$field->name()] = ResourceCompiler::compileLoose($field, [], 'Owner')['valueType'];
        }

        self::assertSame(['count' => 'int', 'whole' => 'int', 'ratio' => 'float', 'amount' => 'int', 'notify' => 'bool', 'day' => 'timestamp', 'at' => 'timestamp', 'note' => 'string'], $valueTypes);
        self::assertFalse(ResourceCompiler::compileLoose(Text::make('note')->required(), [], 'Owner')['nullable']);

        $this->expectException(ResourceCompileException::class);
        $this->expectExceptionMessage('Resource Owner, field "kind": is a select with no options; call options()');
        ResourceCompiler::compileLoose(Select::make('kind'), [], 'Owner');
    }

    public function testALooseRelationLinksToARegisteredResource(): void
    {
        $slugs = [CompilerCustomers::class => 'compiler-customers'];
        $customer = ResourceCompiler::compileLoose(BelongsTo::to('customer', CompilerCustomers::class), $slugs, 'Owner');
        self::assertSame(['compiler-customers', 'int'], [$customer['relation']['resource'], $customer['valueType']]);

        self::assertSame('Resource Owner, field "customer": names no target resource', self::refusal(static fn () => ResourceCompiler::compileLoose(BelongsTo::make('customer'), $slugs, 'Owner')));
        self::assertSame('Resource Owner, field "order": ' . CompilerOrders::class . ' is not a registered resource', self::refusal(static fn () => ResourceCompiler::compileLoose(BelongsTo::to('order', CompilerOrders::class), $slugs, 'Owner')));
    }

    public function testTheFieldPredicatesReadTheCompiledType(): void
    {
        self::assertTrue(ResourceCompiler::isSelect(['type' => 'country']));
        self::assertFalse(ResourceCompiler::isSelect(['type' => 'currency']));
        self::assertFalse(ResourceCompiler::isMultiple(['type' => 'select']), 'a select that states no mode holds one value');
        self::assertTrue(ResourceCompiler::isMultiple(['type' => 'country', 'multiple' => true]));
        self::assertTrue(ResourceCompiler::isList(['type' => 'tag']));
        self::assertTrue(ResourceCompiler::isList(['type' => 'select', 'multiple' => true]));
        self::assertFalse(ResourceCompiler::isList(['type' => 'select', 'multiple' => false]));
        self::assertTrue(ResourceCompiler::isFlagColumns(['type' => 'booleanGroup', 'flags' => ['vip' => 'is_vip']]));
        self::assertFalse(ResourceCompiler::isFlagColumns(['type' => 'booleanGroup']), 'a JSON-mode group maps no flag columns');
        self::assertFalse(ResourceCompiler::isFlagColumns(['type' => 'select', 'flags' => ['vip' => 'is_vip']]));
        self::assertTrue(ResourceCompiler::isCollection(['type' => 'repeater']));
        self::assertFalse(ResourceCompiler::isCollection(['type' => 'tag']));
        self::assertSame(['any', 'all'], ResourceCompiler::operatorsFor(['type' => 'tag', 'filterable' => true]));
    }

    public function testADependentChoiceListNeverMatchesAFlagGroupOrAList(): void
    {
        $others = [
            'booleanGroup' => ['type' => 'booleanGroup', 'label' => 'Flags'],
            'tag' => ['type' => 'tag', 'label' => 'Keywords'],
            'select' => ['type' => 'select', 'multiple' => true, 'label' => 'Platforms'],
        ];

        foreach ($others as $type => $other) {
            $fields = ['other' => $other, 'assignee' => ['type' => 'belongsTo', 'label' => 'Assignee', 'relation' => ['dependsOn' => ['field' => 'other', 'on' => 'team']]]];
            self::assertSame("Resource Owner, field \"assignee\": depends on other, a {$type} field, which holds no single value to match", self::refusal(static fn () => ResourceCompiler::checkDependencies($fields, 'Owner')));
        }
    }

    public function testALooseSelectTakesTheValueTypeOfItsOptions(): void
    {
        $int = ResourceCompiler::compileLoose(Select::make('code')->options([404 => 'Not found', 500 => 'Error']), [], 'Owner');
        self::assertSame(['int', [404, 500]], [$int['valueType'], array_column($int['options'], 'value')], 'a select of integers holds an int');

        $text = ResourceCompiler::compileLoose(Select::make('code')->options([404 => 'Not found', 'gone' => 'Gone']), [], 'Owner');
        self::assertSame(['string', ['404', 'gone']], [$text['valueType'], array_column($text['options'], 'value')], 'one text value makes every value text');

        $many = ResourceCompiler::compileLoose(Select::make('codes')->options(['a' => 'A', 'b' => 'B'])->multiple()->default(['b']), [], 'Owner');
        self::assertSame(['json', null, ['b']], [$many['valueType'], $many['enum'], $many['default']], 'a multiple select holds a list');

        $country = ResourceCompiler::compileLoose(Country::make('country')->only(['FR', 'DE']), [], 'Owner');
        self::assertSame(['string', ['FR', 'DE']], [$country['valueType'], array_column($country['options'], 'value')]);
        self::assertSame(
            'Resource Owner, field "country": offers "XX", which is not an assigned ISO 3166-1 alpha-2 country code',
            self::refusal(static fn () => ResourceCompiler::compileLoose(Country::make('country')->only(['FR', 'XX']), [], 'Owner')),
        );
    }

    public function testALooseFlagGroupTagOrJsonInputHoldsJson(): void
    {
        $flags = ResourceCompiler::compileLoose(BooleanGroup::make('notify')->options(['email' => 'Email', 'sms' => 'SMS'])->default(['sms' => true]), [], 'Owner');
        self::assertSame(['json', 'json', false, ['sms' => true]], [$flags['valueType'], $flags['storage'], $flags['nullable'], $flags['default']]);
        self::assertNull(ResourceCompiler::compileLoose(BooleanGroup::make('notify')->options(['email' => 'Email'])->default(null), [], 'Owner')['default'], 'a null default is no default to check');

        foreach ([['push' => true], ['email'], ['email' => 'yes'], 'email'] as $default) {
            self::assertSame(
                'Resource Owner, field "notify": is a flag group, whose default is an object of some of its option keys to true or false',
                self::refusal(static fn () => ResourceCompiler::compileLoose(BooleanGroup::make('notify')->options(['email' => 'Email'])->default($default), [], 'Owner')),
            );
        }

        self::assertSame('json', ResourceCompiler::compileLoose(TagField::make('keywords'), [], 'Owner')['valueType']);

        $json = ResourceCompiler::compileLoose(Json::make('doc'), [], 'Owner');
        $keyValue = ResourceCompiler::compileLoose(KeyValue::make('pairs'), [], 'Owner');
        self::assertSame([['json', 'objectOrList'], ['json', 'object']], [[$json['valueType'], $json['jsonObjects']], [$keyValue['valueType'], $keyValue['jsonObjects']]]);
        self::assertArrayNotHasKey('jsonObjects', ResourceCompiler::compileLoose(Text::make('note'), [], 'Owner'), 'only a JSON or key-value input keeps its objects');
    }

    public function testTheDescriptorOfAResource(): void
    {
        $compiled = $this->compile();
        $orders = $compiled['compiler-orders'];

        self::assertSame(['compiler-categories', 'compiler-customers', 'compiler-order-lines', 'compiler-orders', 'compiler-products'], array_keys($compiled));
        self::assertSame(['Orders', 'Order'], [$orders['label'], $orders['labelSingular']], 'labels default to the table and the entity');
        self::assertSame(['Clients', 'Client'], [$compiled['compiler-customers']['label'], $compiled['compiler-customers']['labelSingular']]);
        self::assertSame('number', $orders['titleField'], 'the title defaults to the first string field');
        self::assertSame([
            'columns' => ['id', 'number', 'customer', 'status', 'totalCents', 'placedAt', 'lines', 'reference'],
            'extra' => ['currency'],
            'defaultSort' => [['field' => 'id', 'order' => 'asc']],
            'perPage' => 25,
            'perPageOptions' => [10, 25, 50, 100],
            'maxPerPage' => 200,
            'search' => ['number'],
            'groupable' => ['number', 'customer'],
            'summaries' => [],
            'exportLimit' => 10000,
            'exportQueueLimit' => 250000,
            'bulkLimit' => 1000,
        ], $orders['table_view']);
        self::assertSame(['created' => 'createdAt', 'updated' => 'updatedAt'], $orders['timestamps']);
        self::assertSame(['possible' => true, 'reason' => null], $orders['creation'], 'the total has a default, the timestamps are set and the lines are a collection');
        self::assertSame(['lines'], $orders['ownedRelations']);
        self::assertSame(['number' => 'number'], $orders['searchColumns']);
        self::assertTrue($orders['globallySearchable']);
        self::assertFalse($compiled['compiler-customers']['globallySearchable'], 'a resource with nothing to search is not globally searchable');
        self::assertSame(['fields' => ['quantity', 'unitPriceCents'], 'mode' => 'relationship', 'min' => 0, 'max' => 100, 'sortable' => false, 'orderBy' => null], self::field($orders, 'lines')['repeater'], 'a row writes every field but the parent, the hidden product and the identifier');
        self::assertSame(['currency' => 'currency'], self::field($compiled['compiler-order-lines'], 'order')['relation']['carry'], 'a price in the order\'s currency has the order carry it');
        self::assertSame(['readonly' => true, 'nullable' => true, 'valueType' => 'string'], array_intersect_key(self::field($orders, 'reference'), ['readonly' => 0, 'nullable' => 0, 'valueType' => 0]));
    }

    public function testEveryPropertyOfTheEntityIsDescribedForTheWriter(): void
    {
        $compiled = $this->compile(new #[Resource(Tag::class, slug: 'tags')] class extends VectorResource {});
        $categories = $compiled['compiler-categories'];
        $customers = $compiled['compiler-customers'];

        self::assertSame([
            'id' => ['kind' => 'column', 'nullable' => true, 'hasDefault' => true, 'type' => 'int'],
            'name' => ['kind' => 'column', 'nullable' => false, 'hasDefault' => false, 'type' => 'string'],
            'slug' => ['kind' => 'column', 'nullable' => false, 'hasDefault' => false, 'type' => 'string'],
            'description' => ['kind' => 'column', 'nullable' => true, 'hasDefault' => true, 'type' => 'string'],
            'position' => ['kind' => 'column', 'nullable' => false, 'hasDefault' => false, 'type' => 'int'],
            'parent' => ['kind' => 'belongsTo', 'nullable' => true, 'hasDefault' => false, 'type' => 'int'],
            'products' => ['kind' => 'hasMany', 'nullable' => false, 'hasDefault' => false, 'type' => null, 'owned' => false],
        ], $categories['entityProperties']);
        self::assertSame(['kind' => 'manyToMany', 'nullable' => false, 'hasDefault' => false, 'type' => null], $compiled['tags']['entityProperties']['products']);
        self::assertSame(['kind' => 'hasOne', 'nullable' => true, 'hasDefault' => false, 'type' => null, 'owned' => true], $customers['entityProperties']['profile']);
        self::assertSame(['profile'], $customers['ownedRelations']);
        self::assertSame(['created' => 'createdAt', 'updated' => null], $customers['timestamps']);
        self::assertSame(['created' => null, 'updated' => null], $categories['timestamps']);
    }

    public function testCreationNeedsEveryRequiredPropertySupplied(): void
    {
        $categories = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), Text::make('slug'), Number::make('position')];
            }
        };
        $bare = new #[Resource(Order::class, slug: 'bare-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('number')];
            }
        };
        $readonly = new #[Resource(Tag::class, slug: 'readonly-tags')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')->readonly(), Color::make('color')];
            }
        };
        $hidden = new #[Resource(Tag::class, slug: 'hidden-tags', relationTarget: true)] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')->hideFromForms(), Color::make('color')];
            }
        };
        $compiled = $this->compile($categories, $bare, $readonly, $hidden);
        $required = static fn (string $name): array => ['possible' => false, 'reason' => "the entity's \"{$name}\" property is required but not administered; declare a field for it or override create()"];

        self::assertSame(['possible' => true, 'reason' => null], $compiled['compiler-customers']['creation'], 'the timestamp is set and the orders are a collection');
        self::assertSame(['possible' => true, 'reason' => null], $compiled['categories']['creation'], 'a nullable parent may stay empty');
        self::assertSame($required('customer'), $compiled['bare-orders']['creation'], 'the first property nothing supplies is named');
        self::assertSame($required('name'), $compiled['readonly-tags']['creation']);
        self::assertSame($required('name'), $compiled['hidden-tags']['creation']);
    }

    public function testAComputedValueTravelsWithItsSourcesAndAPriceWithItsCurrency(): void
    {
        $orders = new #[Resource(Order::class, slug: 'priced-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [
                    Id::make(),
                    Text::make('number'),
                    Text::make('currency')->hideFromIndex(),
                    Money::make('totalCents')->currencyField('currency'),
                    Computed::make('doubled')->as('money')->currencyField('currency')->from(['totalCents', 'notes'])->method('doubled'),
                    Textarea::make('notes')->hideFromIndex(),
                    Computed::make('summary')->from(['notes', 'number'])->method('summary'),
                ];
            }

            /** @param array<string, mixed> $order */
            public function doubled(array $order): int
            {
                return is_int($order['totalCents'] ?? null) ? 2 * $order['totalCents'] : 0;
            }

            /** @param array<string, mixed> $order */
            public function summary(array $order): string
            {
                return is_string($order['number'] ?? null) ? $order['number'] : '';
            }
        };
        $compiled = $this->compile($orders)['priced-orders'];

        self::assertSame(['id', 'number', 'totalCents', 'doubled', 'summary'], $compiled['table_view']['columns']);
        self::assertSame(['currency', 'notes'], $compiled['table_view']['extra'], 'each hidden dependency once, none already shown');
        self::assertSame([], $compiled['show_view']['extra']);
    }

    public function testARelationLinksToTheMarkedResourceOfItsEntity(): void
    {
        $orders = new #[Resource(Order::class, slug: 'derived-orders')] class extends VectorResource {};
        $lines = new #[Resource(OrderLine::class, slug: 'derived-lines')] class extends VectorResource {};
        $compiled = $this->compile($orders, $lines)['derived-lines'];

        self::assertSame('compiler-orders', self::field($compiled, 'order')['relation']['resource'], 'compiler-orders is marked though discovered second');
        self::assertSame('compiler-products', self::field($compiled, 'product')['relation']['resource']);
    }

    public function testDerivationMapsEachPropertyTypeToItsField(): void
    {
        $products = new #[Resource(Product::class, slug: 'derived-products')] class extends VectorResource {};
        $tags = new #[Resource(Tag::class, slug: 'derived-tags')] class extends VectorResource {};
        $compiled = $this->compile($products, $tags)['derived-products'];

        self::assertSame(['number', ['decimals' => 2]], [self::field($compiled, 'weightKg')['type'], self::field($compiled, 'weightKg')['props']]);
        self::assertSame('boolean', self::field($compiled, 'isFeatured')['type']);
        self::assertSame(['manyToMany', false, 'derived-tags'], [self::field($compiled, 'tags')['type'], self::field($compiled, 'tags')['nullable'], self::field($compiled, 'tags')['relation']['resource']]);
        self::assertSame('sku', $compiled['titleField']);
    }

    public function testTheTitleSkipsSelectAndLongTextFields(): void
    {
        $orders = new #[Resource(Order::class, slug: 'titled-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Select::make('status')->of(OrderStatus::class), Textarea::make('notes'), Text::make('number')];
            }
        };

        self::assertSame('number', $this->compile($orders)['titled-orders']['titleField']);
    }

    public function testTimestampsMayBeDeclaredAndMustBeTimestamps(): void
    {
        $none = new #[Resource(Customer::class, slug: 'unstamped-customers')] class extends VectorResource {
            public function timestamps(): array
            {
                return ['created' => null, 'updated' => null];
            }
        };
        self::assertSame(['created' => null, 'updated' => null], $this->compile($none)['unstamped-customers']['timestamps']);

        $wrong = new #[Resource(Customer::class, slug: 'misstamped-customers')] class extends VectorResource {
            public function timestamps(): array
            {
                return ['created' => 'name', 'updated' => null];
            }
        };
        $this->assertRefused('Resource ' . $wrong::class . ': timestamp property "name" is not a DateTimeImmutable property of App\Domain\Customer', $wrong);
    }

    public function testSoftDeletionNamesANullableTimestamp(): void
    {
        $unmapped = new #[Resource(Customer::class, slug: 'soft-customers')] class extends VectorResource {
            public function softDeletes(): string
            {
                return 'deletedAt';
            }
        };
        $this->assertRefused('Resource ' . $unmapped::class . ': softDeletes() names "deletedAt", which is not a mapped property of App\Domain\Customer', $unmapped);

        $required = new #[Resource(Customer::class, slug: 'soft-customers')] class extends VectorResource {
            public function softDeletes(): string
            {
                return 'createdAt';
            }
        };
        $this->assertRefused('Resource ' . $required::class . ': softDeletes() names "createdAt", which is not a nullable DateTimeImmutable property', $required);
    }

    public function testASlugBelongsToOneResource(): void
    {
        $first = new #[Resource(Tag::class, slug: 'labels')] class extends VectorResource {};
        $second = new #[Resource(Tag::class, slug: 'labels')] class extends VectorResource {};

        $this->expectException(ResourceCompileException::class);
        $this->expectExceptionMessage('Resource ' . $second::class . ': slug "labels" is already used by ' . $first::class);
        new ResourceCompiler(MetadataRegistry::fromClasses(self::ENTITIES), [$first::class, $second::class]);
    }

    public function testAFieldMustBeSortableSearchableOrSummarizedOnlyWhereItsColumnAllows(): void
    {
        $sort = new #[Resource(Tag::class, slug: 'tags')] class extends VectorResource {
            public function defaultSort(): array
            {
                return ['-shade'];
            }
        };
        $this->assertRefused('Resource ' . $sort::class . ': default sort names "shade", which is not a sortable field', $sort);

        $search = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), Number::make('position')->searchable()];
            }
        };
        $this->assertRefused(self::fieldRefusal($search, 'position', 'is not a string column and cannot be searchable'), $search);

        $summary = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')->summarize('sum')];
            }
        };
        $this->assertRefused(self::fieldRefusal($summary, 'name', 'is not a numeric column and cannot carry a summary'), $summary);
    }

    public function testAConditionNamesAnotherFieldAndACollectionOnlyByPresence(): void
    {
        $valid = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [
                    Id::make(),
                    Text::make('name'),
                    Textarea::make('description'),
                    Text::make('slug')->visibleWhen('products', 'nnull')->disabledWhen('description', 'null'),
                    HasMany::to('products', CompilerProducts::class),
                ];
            }
        };
        self::assertSame(
            ['visible' => [['field' => 'products', 'op' => 'nnull', 'value' => null]], 'required' => [], 'disabled' => [['field' => 'description', 'op' => 'null', 'value' => null]]],
            self::field($this->compile($valid)['categories'], 'slug')['conditions'],
        );

        $missing = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')->visibleWhen('missing', 'eq', 'x')];
            }
        };
        $this->assertRefused(self::fieldRefusal($missing, 'name', 'has a visible condition on "missing", which is not a field of the same form'), $missing);

        $collection = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')->requiredWhen('products', 'eq', 'x'), HasMany::to('products', CompilerProducts::class)];
            }
        };
        $this->assertRefused(self::fieldRefusal($collection, 'name', 'has a required condition on the collection "products"; only null and nnull apply'), $collection);
    }

    public function testAComputedFieldIsAPublicMethodShownAndNeverWritten(): void
    {
        $valid = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), Computed::make('tier')->as('select')->options(['top' => 'Top'])->from(['name'])->method('tier'), Computed::make('trashed')->method('tier')];
            }

            /** @param array<string, mixed> $category */
            public function tier(array $category): string
            {
                return 'top';
            }
        };
        $tier = self::field($this->compile($valid)['categories'], 'tier');
        self::assertSame([true, true, 'string'], [$tier['readonly'], $tier['nullable'], $tier['valueType']]);

        $noMethod = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), Computed::make('tier')];
            }
        };
        $this->assertRefused(self::fieldRefusal($noMethod, 'tier', 'is computed but names no method; call method()'), $noMethod);

        $protected = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), Computed::make('tier')->method('tier')];
            }

            protected function tier(): string
            {
                return 'top';
            }
        };
        $this->assertRefused(self::fieldRefusal($protected, 'tier', 'is computed by tier(), which is not a public instance method of the resource'), $protected);

        $static = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), Computed::make('tier')->method('tier')];
            }

            public static function tier(): string
            {
                return 'top';
            }
        };
        $this->assertRefused(self::fieldRefusal($static, 'tier', 'is computed by tier(), which is not a public instance method of the resource'), $static);

        $sortable = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), Computed::make('tier')->method('fields')->sortable()];
            }
        };
        $this->assertRefused(self::fieldRefusal($sortable, 'tier', 'is computed per record in PHP, so it cannot be sortable'), $sortable);

        $searchable = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), Computed::make('tier')->method('fields')->searchable()];
            }
        };
        $this->assertRefused(self::fieldRefusal($searchable, 'tier', 'is computed per record in PHP, so it cannot be searchable'), $searchable);

        $select = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), Computed::make('tier')->as('select')->method('fields')];
            }
        };
        $this->assertRefused(self::fieldRefusal($select, 'tier', 'is computed as a select but lists no options; call options()'), $select);

        $collection = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), HasMany::to('products', CompilerProducts::class), Computed::make('tier')->from(['products'])->method('fields')];
            }
        };
        $this->assertRefused(self::fieldRefusal($collection, 'tier', 'is computed from "products", which is not a field of the resource holding a value of its own'), $collection);
    }

    public function testAMenuBadgeFiltersOnFilterableFieldsWithTheirOperators(): void
    {
        $valid = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')->filterable(), Number::make('position')->filterable()];
            }

            public function badge(): Badge
            {
                return Badge::count(['trashed' => 'only', 'name' => ['like' => 'a'], 'or' => [['position' => ['gt' => 1]], ['name' => 'b']]], 'warning', 'busy');
            }
        };
        self::assertSame([
            'filter' => ['trashed' => ['eq' => 'only'], 'name' => ['like' => 'a'], 'or' => [['position' => ['gt' => '1']], ['name' => ['eq' => 'b']]]],
            'color' => 'warning',
            'label' => 'busy',
        ], $this->compile($valid)['categories']['badge']);

        $blank = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function badge(): Badge
            {
                return Badge::count([], ' ');
            }
        };
        $this->assertRefused($blank::class . '::badge() has no color.', $blank);

        $unfilterable = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')];
            }

            public function badge(): Badge
            {
                return Badge::count(['trashed' => 'only', 'name' => 'a']);
            }
        };
        $this->assertRefused($unfilterable::class . '::badge() filters on "name", which is not a filterable field of the resource.', $unfilterable);

        $operator = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')->filterable()];
            }

            public function badge(): Badge
            {
                return Badge::count(['or' => [['name' => 'b']], 'name' => ['gt' => 'a']]);
            }
        };
        $this->assertRefused($operator::class . '::badge() filters "name" with "gt", which that field does not take.', $operator);

        $nested = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')->filterable(), Text::make('slug')];
            }

            public function badge(): Badge
            {
                return Badge::count(['and' => [['name' => 'a'], ['slug' => 'b']]]);
            }
        };
        $this->assertRefused($nested::class . '::badge() filters on "slug", which is not a filterable field of the resource.', $nested);
    }

    public function testASearchResultShowsATextSubtitleAndAnImage(): void
    {
        $subtitle = new #[Resource(Tag::class, slug: 'tags')] class extends VectorResource {
            public function searchSubtitle(): string
            {
                return 'id';
            }
        };
        $this->assertRefused($subtitle::class . '::searchSubtitle() names "id", which is not a text, number or single select field of the resource.', $subtitle);

        $image = new #[Resource(Tag::class, slug: 'tags')] class extends VectorResource {
            public function searchImage(): string
            {
                return 'name';
            }
        };
        $this->assertRefused($image::class . '::searchImage() names "name", which is not an image field of the resource.', $image);
    }

    public function testARichTextFieldNamesItsImagesMentionsAndPlainText(): void
    {
        $valid = new #[Resource(Product::class, slug: 'products')] class extends VectorResource {
            public function fields(): array
            {
                return [
                    Id::make(),
                    Text::make('name'),
                    Image::make('imageKey'),
                    BelongsTo::to('category', CompilerCategories::class),
                    RichText::make('description')->imageField('imageKey')->mentions('category')->textField('slug')->searchable(),
                ];
            }
        };
        $compiled = $this->compile($valid)['products'];
        self::assertSame(['slug', 'slug'], [self::field($compiled, 'description')['textColumn'], self::field($compiled, 'description')['searchColumn']]);
        self::assertSame(['description' => 'slug'], $compiled['searchColumns'], 'a rich-text field is searched through its plain-text column');

        $mentions = new #[Resource(Product::class, slug: 'products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), RichText::make('description')->mentions('name')];
            }
        };
        $this->assertRefused(self::fieldRefusal($mentions, 'description', 'allows mentions, which need mentions() naming a belongsTo or manyToMany field of the resource'), $mentions);

        $images = new #[Resource(Product::class, slug: 'products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), RichText::make('description')->imageField('name')];
            }
        };
        $this->assertRefused(self::fieldRefusal($images, 'description', 'allows images, which need imageField() naming a file or image field of the resource'), $images);

        $textField = new #[Resource(Product::class, slug: 'products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), RichText::make('description')->textField('stock')];
            }
        };
        $this->assertRefused(self::fieldRefusal($textField, 'description', 'names textField "stock", which is not a string property of the entity'), $textField);

        $searchable = new #[Resource(Product::class, slug: 'products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), RichText::make('description')->searchable()];
            }
        };
        $this->assertRefused(self::fieldRefusal($searchable, 'description', 'is a rich-text field; searching it needs textField() naming a string property the writer fills with its plain text'), $searchable);

        $sortable = new #[Resource(Product::class, slug: 'products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), RichText::make('description')->sortable()];
            }
        };
        $this->assertRefused(self::fieldRefusal($sortable, 'description', 'is a rich-text field, which cannot be sorted or summarized'), $sortable);
    }

    public function testASearchableRelationMatchesItsTargetsTitleAndFullTextCoversSearchableTextFields(): void
    {
        $products = new #[Resource(Product::class, slug: 'products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('sku')->searchable(), Text::make('name')->searchable(), Textarea::make('description')->searchable(), BelongsTo::to('category', CompilerCategories::class)->searchable()];
            }

            public function fullText(): FullText
            {
                return FullText::on('description', 'name')->config('english');
            }
        };
        $compiled = $this->compile($products)['products'];

        self::assertSame(['sku', 'name', 'description', 'category'], $compiled['table_view']['search'], 'in the order of the fields');
        self::assertSame(['sku' => 'sku'], $compiled['searchColumns'], 'LIKE leaves the indexed columns to the index');
        self::assertSame(['category' => ['column' => 'category_id', 'resource' => 'compiler-categories']], $compiled['searchRelations']);
        self::assertSame(['columns' => ['description' => 'description', 'name' => 'name'], 'config' => 'english'], $compiled['fullText'], 'in the order the index lists them');
        self::assertTrue(self::field($compiled, 'category')['searchable']);

        // a searchable relation, or a full-text index, is enough to search on its own
        $lines = new #[Resource(OrderLine::class, slug: 'lines')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), BelongsTo::to('order', CompilerOrders::class)->searchable(), Number::make('quantity')];
            }
        };
        $compiled = $this->compile($lines)['lines'];
        self::assertSame([[], ['order'], true], [$compiled['searchColumns'], $compiled['table_view']['search'], $compiled['globallySearchable']]);
        self::assertArrayNotHasKey('fullText', $compiled);

        $indexed = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')->searchable()];
            }

            public function fullText(): FullText
            {
                return FullText::on('name');
            }
        };
        $compiled = $this->compile($indexed)['categories'];
        self::assertSame([[], ['name'], true, 'simple'], [$compiled['searchColumns'], $compiled['table_view']['search'], $compiled['globallySearchable'], $compiled['fullText']['config']]);
        self::assertArrayNotHasKey('searchRelations', $compiled);
    }

    public function testASearchableRelationNeedsATextTitle(): void
    {
        $lines = new #[Resource(OrderLine::class, slug: 'lines')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), BelongsTo::to('order', UntitledOrders::class)->searchable()];
            }
        };
        $this->assertRefused(self::fieldRefusal($lines, 'order', 'is searchable, but the title field "id" of untitled-orders is not a string column'), new UntitledOrders(), $lines);

        // a computed title has no column to match: the target's own compile refuses it first
        $computed = new #[Resource(OrderLine::class, slug: 'lines')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), BelongsTo::to('order', ComputedTitleOrders::class)->searchable()];
            }
        };
        $this->assertRefused('Resource ' . ComputedTitleOrders::class . ': title field "label" maps no column', new ComputedTitleOrders(), $computed);

        // only a belongsTo matches through its target
        $orders = new #[Resource(Order::class, slug: 'orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('number'), HasMany::to('lines', CompilerOrderLines::class)->searchable()];
            }
        };
        $this->assertRefused(self::fieldRefusal($orders, 'lines', 'is not a string column and cannot be searchable'), $orders);
    }

    public function testFullTextNamesSearchableTextFieldsAndAConfigurationName(): void
    {
        $refusals = [
            'fullText() names no field' => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const array FIELDS = []; },
            'fullText() names "nope", which is not a text field of the resource' => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const array FIELDS = ['name', 'nope']; },
            'fullText() names "position", which is not a text field of the resource' => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const array FIELDS = ['position']; },
            'fullText() names "label", which is not a text field of the resource' => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const array FIELDS = ['label']; },
            'fullText() names "slug", which is not searchable' => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const array FIELDS = ['name', 'slug']; },
            'fullText() names "name" twice' => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const array FIELDS = ['name', 'description', 'name']; },
        ];

        foreach ($refusals as $reason => $resource) {
            $this->assertRefused('Resource ' . $resource::class . ": {$reason}", $resource);
        }

        $configs = [
            'english' => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const string CONFIG = 'english'; },
            'x' => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const string CONFIG = 'x'; },
            'pg_simple2' => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const string CONFIG = 'pg_simple2'; },
            str_repeat('x', 63) => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const string CONFIG = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'; },
        ];

        foreach ($configs as $config => $resource) {
            self::assertSame((string) $config, $this->compile($resource)['categories']['fullText']['config']);
        }

        // a name PostgreSQL could read as something else, or a literal it could break out of
        $names = [
            '' => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const string CONFIG = ''; },
            'English' => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const string CONFIG = 'English'; },
            'pg_catalog.english' => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const string CONFIG = 'pg_catalog.english'; },
            "english'" => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const string CONFIG = "english'"; },
            ' english' => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const string CONFIG = ' english'; },
            'english ' => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const string CONFIG = 'english '; },
            '2english' => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const string CONFIG = '2english'; },
            str_repeat('x', 64) => new #[Resource(Category::class, slug: 'categories')] class extends FullTextCategories { public const string CONFIG = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'; },
        ];

        foreach ($names as $config => $resource) {
            self::assertSame($config, $resource::CONFIG);
            $this->assertRefused('Resource ' . $resource::class . ": fullText() configures \"{$config}\", which is not a PostgreSQL text search configuration name", $resource);
        }
    }

    public function testAKeyValueFieldCannotBeSortedFilteredOrSearched(): void
    {
        $refusal = 'is a key-value field, which cannot be sorted, filtered, searched or summarized';

        $sortable = new #[Resource(Product::class, slug: 'products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), KeyValue::make('attributes')->sortable()];
            }
        };
        $this->assertRefused(self::fieldRefusal($sortable, 'attributes', $refusal), $sortable);

        $filterable = new #[Resource(Product::class, slug: 'products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), KeyValue::make('attributes')->filterable()];
            }
        };
        $this->assertRefused(self::fieldRefusal($filterable, 'attributes', $refusal), $filterable);

        $searchable = new #[Resource(Product::class, slug: 'products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), KeyValue::make('attributes')->searchable()];
            }
        };
        $this->assertRefused(self::fieldRefusal($searchable, 'attributes', $refusal), $searchable);
    }

    public function testAHashedPasswordNamesAPasswordHasher(): void
    {
        $plain = new #[Resource(User::class, slug: 'users')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), Password::make('passwordHash')];
            }
        };
        self::assertSame([], self::field($this->compile($plain)['users'], 'passwordHash')['props']);

        $hashed = new #[Resource(User::class, slug: 'users')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), Password::make('passwordHash')->hashed(DemoPasswordHasher::class)];
            }
        };
        self::assertSame(['hasher' => DemoPasswordHasher::class], self::field($this->compile($hashed)['users'], 'passwordHash')['props']);

        $notAHasher = new #[Resource(User::class, slug: 'users')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), Password::make('passwordHash')->props(['hasher' => \stdClass::class])];
            }
        };
        $this->assertRefused(self::fieldRefusal($notAHasher, 'passwordHash', 'is hashed by stdClass, which does not implement ' . PasswordHasherInterface::class), $notAHasher);

        $notAClass = new #[Resource(User::class, slug: 'users')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), Password::make('passwordHash')->props(['hasher' => 42])];
            }
        };
        $this->assertRefused(self::fieldRefusal($notAClass, 'passwordHash', 'is hashed by int, which does not implement ' . PasswordHasherInterface::class), $notAClass);
    }

    public function testACurrencyFieldDefaultsToAnAllowedCode(): void
    {
        $valid = new #[Resource(Order::class, slug: 'orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('number'), Currency::make('currency')->allowed(['EUR', 'USD'])->default('EUR')];
            }
        };
        self::assertSame('EUR', self::field($this->compile($valid)['orders'], 'currency')['default']);

        $outside = new #[Resource(Order::class, slug: 'orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('number'), Currency::make('currency')->allowed(['EUR', 'USD'])->default('JPY')];
            }
        };
        $this->assertRefused(self::fieldRefusal($outside, 'currency', 'defaults to a currency it does not allow'), $outside);
    }

    public function testAnInlineFieldIsASimpleValueEditableOnUpdate(): void
    {
        $text = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name'), Textarea::make('description')->inlineEditable()];
            }
        };
        $this->assertRefused(self::fieldRefusal($text, 'description', 'is a text field, which cannot be edited inline'), $text);

        $hidden = new #[Resource(Category::class, slug: 'categories')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')->inlineEditable()->hideFromForms()];
            }
        };
        $this->assertRefused(self::fieldRefusal($hidden, 'name', 'is inline-editable but not editable on update'), $hidden);
    }

    public function testAPriceInARelatedCurrencyNamesATextColumnOfTheRelation(): void
    {
        $nested = new #[Resource(OrderLine::class, slug: 'lines')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), BelongsTo::to('order', CompilerOrders::class), Money::make('unitPriceCents')->currencyField('order.currency.code')];
            }
        };
        $this->assertRefused(self::fieldRefusal($nested, 'unitPriceCents', 'currencyField "order.currency.code" names no text column of compiler-orders'), $nested);

        $computed = new #[Resource(OrderLine::class, slug: 'lines')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), BelongsTo::to('order', CompilerOrders::class), Money::make('unitPriceCents')->currencyField('order.reference')];
            }
        };
        $this->assertRefused(self::fieldRefusal($computed, 'unitPriceCents', 'currencyField "order.reference" names no text column of compiler-orders'), $computed);
    }

    public function testARepeaterRowWritesTheFieldsItNamesAndEveryRequiredOne(): void
    {
        $ordered = new #[Resource(Order::class, slug: 'orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('lines')->relationship('lines', CompilerOrderLines::class)->fields(['unitPriceCents', 'quantity'])->min(1)->max(5)];
            }
        };
        self::assertSame(['fields' => ['unitPriceCents', 'quantity'], 'mode' => 'relationship', 'min' => 1, 'max' => 5, 'sortable' => false, 'orderBy' => null], self::field($this->compile($ordered)['orders'], 'lines')['repeater']);

        $parent = new #[Resource(Order::class, slug: 'orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('lines')->relationship('lines', CompilerOrderLines::class)->fields(['order', 'quantity', 'unitPriceCents'])];
            }
        };
        $this->assertRefused(self::fieldRefusal($parent, 'lines', 'names "order", which is not a writable field of Order line'), $parent);

        $partial = new #[Resource(Order::class, slug: 'orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('lines')->relationship('lines', CompilerOrderLines::class)->fields(['quantity'])];
            }
        };
        $this->assertRefused(self::fieldRefusal($partial, 'lines', 'leaves out "unitPriceCents", which a new Order line row requires'), $partial);
    }

    public function testARelationNamesARegisteredResourceOverItsMappedRelationship(): void
    {
        $refusals = [
            ['category', 'names no target resource', new #[Resource(Product::class, slug: 'products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Text::make('name'), BelongsTo::make('category')];
                }
            }],
            ['category', TagResource::class . ' is not a registered resource', new #[Resource(Product::class, slug: 'products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Text::make('name'), BelongsTo::to('category', TagResource::class)];
                }
            }],
            ['maker', 'is not a #[BelongsTo] property', new #[Resource(Product::class, slug: 'products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Text::make('name'), BelongsTo::to('maker', CompilerCategories::class)];
                }
            }],
            ['category', 'is not a #[HasOne]/#[HasMany] property', new #[Resource(Product::class, slug: 'products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Text::make('name'), HasMany::to('category', CompilerCategories::class)];
                }
            }],
            ['category', 'is not a #[ManyToMany] property', new #[Resource(Product::class, slug: 'products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Text::make('name'), ManyToMany::to('category', CompilerCategories::class)];
                }
            }],
            ['category', 'depends on nope, which App\Domain\Category does not map', new #[Resource(Product::class, slug: 'products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Text::make('name'), BelongsTo::to('category', CompilerCategories::class)->dependsOn('name', 'nope')];
                }
            }],
        ];

        foreach ($refusals as [$field, $reason, $resource]) {
            $this->assertRefused(self::fieldRefusal($resource, $field, $reason), $resource);
        }
    }

    public function testAnActionIsCompiledWithItsFields(): void
    {
        $valid = new #[Resource(Tag::class, slug: 'tags')] class extends VectorResource {
            public function actions(): array
            {
                return [CompilerAssignAction::class];
            }
        };
        $actions = $this->compile($valid)['tags']['actions'];

        self::assertSame(['compiler-assign'], array_column($actions, 'name'));
        self::assertArrayHasKey(0, $actions, 'the actions are a list');
        self::assertSame(1, $actions[0]['chunkSize'], 'a chunk holds at least one record');
        self::assertSame(['compiler-customers', 'int'], [$actions[0]['fields'][0]['relation']['resource'], $actions[0]['fields'][0]['valueType']]);

        $badName = new #[Resource(Tag::class, slug: 'tags')] class extends VectorResource {
            public function actions(): array
            {
                return [CompilerBadNameAction::class];
            }
        };
        $this->assertRefused('Resource ' . $badName::class . ': action ' . CompilerBadNameAction::class . ' has the name "not_kebab"; an action name is lower-case kebab-case', $badName);

        $condition = new #[Resource(Tag::class, slug: 'tags')] class extends VectorResource {
            public function actions(): array
            {
                return [CompilerConditionAction::class];
            }
        };
        $this->assertRefused('Resource ' . CompilerConditionAction::class . ', field "note": has a visible condition on "missing", which is not a field of the same form', $condition);
    }

    public function testAFlagGroupInColumnsModeWritesEachFlagsBoolColumn(): void
    {
        $board = new #[Resource(CompilerBoard::class, slug: 'boards')] class extends VectorResource {
            public function fields(): array
            {
                return [
                    Id::make(),
                    Text::make('title'),
                    // a flag with a default, one without, then one with again: the group starts with no value
                    BooleanGroup::make('state')->options(['isMuted' => 'Muted', 'isActive' => 'Active', 'isPinned' => 'Pinned'])->sortable()->filterable(),
                    // every flag with a default: so has the group
                    BooleanGroup::make('lock')->options(['isLocked' => 'Locked']),
                    // a default of its own wins over the properties'
                    BooleanGroup::make('star')->options(['isStarred' => 'Starred'])->default(['isStarred' => true]),
                ];
            }

            public function defaultSort(): array
            {
                return ['-state.isActive', 'id'];
            }
        };
        $compiled = $this->compile($board)['boards'];
        $state = self::field($compiled, 'state');

        self::assertSame(['isMuted' => 'is_muted', 'isActive' => 'is_active', 'isPinned' => 'is_pinned'], $state['flags']);
        self::assertSame([false, 'json', false, true], [$state['nullable'], $state['valueType'], $state['sortable'], $state['flagsSortable']], 'the group sorts by each flag, never as a whole');
        self::assertSame([false, null], [$state['hasDefault'], $state['default']]);
        self::assertSame([true, ['isLocked' => true]], [self::field($compiled, 'lock')['hasDefault'], self::field($compiled, 'lock')['default']]);
        self::assertSame(['isStarred' => true], self::field($compiled, 'star')['default']);
        self::assertSame('is_active', $compiled['columns']['state.isActive']);
        self::assertSame([['field' => 'state.isActive', 'order' => 'desc'], ['field' => 'id', 'order' => 'asc']], $compiled['table_view']['defaultSort']);
        self::assertSame(['possible' => true, 'reason' => null], $compiled['creation'], 'the group writes the required isActive');
        self::assertSame(['notes'], $compiled['ownedRelations'], 'the owned relations are a list');

        $deeper = new #[Resource(CompilerBoard::class, slug: 'boards')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), BooleanGroup::make('state')->options(['isActive' => 'Active'])->sortable()];
            }

            public function defaultSort(): array
            {
                return ['state.isActive.x'];
            }
        };
        $this->assertRefused('Resource ' . $deeper::class . ': default sort names "state.isActive.x", which is not a sortable field', $deeper);
    }

    public function testAFlagIsABoolPropertyWrittenByOneGroupAlone(): void
    {
        $notBool = [
            'nope' => new #[Resource(CompilerBoard::class, slug: 'boards')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), BooleanGroup::make('state')->options(['nope' => 'Unmapped'])];
                }
            },
            'title' => new #[Resource(CompilerBoard::class, slug: 'boards')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), BooleanGroup::make('state')->options(['title' => 'A string'])];
                }
            },
            'isArchived' => new #[Resource(CompilerBoard::class, slug: 'boards')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), BooleanGroup::make('state')->options(['isArchived' => 'A nullable bool'])];
                }
            },
        ];

        foreach ($notBool as $flag => $resource) {
            $this->assertRefused(self::fieldRefusal($resource, 'state', "names the flag \"{$flag}\", which is not a bool property of " . CompilerBoard::class . '; in columns mode every option key is one, or call json()'), $resource);
        }

        $field = new #[Resource(CompilerBoard::class, slug: 'boards')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Boolean::make('isPinned'), BooleanGroup::make('state')->options(['isActive' => 'Active', 'isPinned' => 'Pinned'])];
            }
        };
        $this->assertRefused(self::fieldRefusal($field, 'state', 'names the flag "isPinned", which is also a field of the resource; a flag is written by its group alone'), $field);

        $twice = new #[Resource(CompilerBoard::class, slug: 'boards')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), BooleanGroup::make('state')->options(['isActive' => 'Active']), BooleanGroup::make('quiet')->options(['isMuted' => 'Muted', 'isActive' => 'Active'])];
            }
        };
        $this->assertRefused(self::fieldRefusal($twice, 'state', 'names the flag "isActive", which the flag group "quiet" names too; a flag is written by one group alone'), $twice);
    }

    public function testCreationCountsTheFlagsOfEveryGroupOnTheForm(): void
    {
        // isActive, which a new board requires, is neither the first flag of its group nor in the last group
        $board = new #[Resource(CompilerBoard::class, slug: 'boards')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), BooleanGroup::make('state')->options(['isMuted' => 'Muted', 'isActive' => 'Active']), BooleanGroup::make('lock')->options(['isLocked' => 'Locked'])];
            }
        };
        self::assertSame(['possible' => true, 'reason' => null], $this->compile($board)['boards']['creation']);

        $hidden = new #[Resource(CompilerBoard::class, slug: 'boards')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), BooleanGroup::make('state')->options(['isActive' => 'Active'])->hideFromForms()];
            }
        };
        self::assertFalse($this->compile($hidden)['boards']['creation']['possible'], 'a group off the form writes nothing');
    }

    public function testAFieldWithNoColumnCannotBeSortable(): void
    {
        $orders = new #[Resource(Order::class, slug: 'orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), HasMany::to('lines', CompilerOrderLines::class)->sortable()];
            }
        };
        $this->assertRefused(self::fieldRefusal($orders, 'lines', 'maps no column and cannot be sortable'), $orders);
    }

    private function assertRefused(string $message, VectorResource ...$resources): void
    {
        try {
            $this->compile(...$resources);
        } catch (ResourceCompileException $e) {
            self::assertSame($message, $e->getMessage());

            return;
        }

        self::fail("The compiler did not refuse: {$message}");
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

    /** @return array<string, array<string, mixed>> slug => compiled resource */
    private function compile(VectorResource ...$resources): array
    {
        $classes = array_map(static fn (VectorResource $resource): string => $resource::class, array_values($resources));

        return (new ResourceCompiler(MetadataRegistry::fromClasses(self::ENTITIES), [...$classes, ...self::FIXTURES]))->compile();
    }

    private static function fieldRefusal(VectorResource $resource, string $field, string $reason): string
    {
        return 'Resource ' . $resource::class . ", field \"{$field}\": {$reason}";
    }

    /**
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    private static function field(array $resource, string $name): array
    {
        foreach ($resource['fields'] as $field) {
            if ($field['name'] === $name) {
                return $field;
            }
        }

        self::fail("No field {$name}.");
    }
}

#[Resource(Customer::class, slug: 'compiler-customers', label: 'Clients', labelSingular: 'Client', relationTarget: true)]
final class CompilerCustomers extends VectorResource
{
    public function fields(): array
    {
        return [Id::make(), Email::make('email'), Text::make('name')];
    }
}

/** Orders written with their lines; the total keeps its default. */
#[Resource(Order::class, slug: 'compiler-orders', relationTarget: true)]
final class CompilerOrders extends VectorResource
{
    public function fields(): array
    {
        return [
            Id::make(),
            Text::make('number')->sortable()->searchable(),
            BelongsTo::to('customer', CompilerCustomers::class)->sortable(),
            Select::make('status')->of(OrderStatus::class)->filterable(),
            Text::make('currency')->hideFromIndex(),
            Money::make('totalCents')->currencyField('currency')->readonly(),
            DateTime::make('placedAt')->sortable(),
            Repeater::make('lines')->relationship('lines', CompilerOrderLines::class),
            Computed::make('reference')->method('reference'),
        ];
    }

    /** @param array<string, mixed> $order */
    public function reference(array $order): string
    {
        return is_string($order['number'] ?? null) ? "#{$order['number']}" : '';
    }
}

/** Lines priced in their order's currency; the product is set elsewhere. */
#[Resource(OrderLine::class, slug: 'compiler-order-lines', relationTarget: true)]
final class CompilerOrderLines extends VectorResource
{
    public function fields(): array
    {
        return [
            Id::make(),
            BelongsTo::to('order', CompilerOrders::class),
            BelongsTo::to('product', CompilerProducts::class)->hideFromForms(),
            Number::make('quantity')->required(),
            Money::make('unitPriceCents')->currencyField('order.currency')->required(),
        ];
    }
}

#[Resource(Product::class, slug: 'compiler-products', relationTarget: true)]
final class CompilerProducts extends VectorResource
{
    public function fields(): array
    {
        return [Id::make(), Text::make('name')];
    }
}

#[Resource(Category::class, slug: 'compiler-categories', relationTarget: true)]
final class CompilerCategories extends VectorResource
{
    public function fields(): array
    {
        return [Id::make(), Text::make('name')];
    }
}

final class CompilerAssignAction extends VectorAction
{
    public function fields(): array
    {
        return [BelongsTo::to('customer', CompilerCustomers::class)->required()];
    }

    public function chunkSize(): int
    {
        return 0;
    }

    public function handle(ActionRequest $request): ActionResponse
    {
        return ActionResponse::message('Assigned.');
    }
}

final class CompilerBadNameAction extends VectorAction
{
    public function name(): string
    {
        return 'not_kebab';
    }

    public function handle(ActionRequest $request): ActionResponse
    {
        return ActionResponse::message('Done.');
    }
}

final class CompilerConditionAction extends VectorAction
{
    public function fields(): array
    {
        return [Text::make('note')->visibleWhen('missing', 'eq', 'x')];
    }

    public function handle(ActionRequest $request): ActionResponse
    {
        return ActionResponse::message('Done.');
    }
}

/** Categories searched through the full-text declaration a subclass names in FIELDS and CONFIG. */
abstract class FullTextCategories extends VectorResource
{
    /** @var list<string> */
    public const array FIELDS = ['name'];

    public const string CONFIG = 'simple';

    public function fields(): array
    {
        return [
            Id::make(),
            Text::make('name')->searchable(),
            Textarea::make('description')->searchable(),
            Text::make('slug'),
            // a text field over an integer column
            Text::make('position'),
            Computed::make('label')->method('label'),
        ];
    }

    public function fullText(): FullText
    {
        return FullText::on(...static::FIELDS)->config(static::CONFIG);
    }

    /** @param array<string, mixed> $category */
    public function label(array $category): string
    {
        return '';
    }
}

/** Orders titled by a computed string: no column to match a search against. */
#[Resource(Order::class, slug: 'computed-title-orders', title: 'label')]
final class ComputedTitleOrders extends VectorResource
{
    public function fields(): array
    {
        return [Id::make(), Text::make('number'), Computed::make('label')->from(['number'])->method('label')];
    }

    /** @param array<string, mixed> $order */
    public function label(array $order): string
    {
        return 'Order ' . (is_scalar($order['number'] ?? null) ? (string) $order['number'] : '');
    }
}

/** Orders titled by their key: nothing to match a search against. */
#[Resource(Order::class, slug: 'untitled-orders')]
final class UntitledOrders extends VectorResource
{
    public function fields(): array
    {
        return [Id::make(), Number::make('totalCents')];
    }
}

/**
 * A board: bool flags with and without a default (isActive, required,
 * maps the column is_active), a nullable one, an int-backed enum, its
 * cards in a relation it does not own and its notes in one it does.
 */
#[Entity(table: 'compiler_boards')]
final class CompilerBoard
{
    #[EntityId(generated: true)]
    public ?int $id = null;

    public string $title = '';

    public bool $isActive;

    public bool $isMuted = false;

    public bool $isPinned = true;

    public bool $isLocked = true;

    public bool $isStarred = false;

    public ?bool $isArchived = null;

    public Level $level = Level::Low;

    /** @var list<CompilerCard> */
    #[OrmHasMany(target: CompilerCard::class, mappedBy: 'board')]
    public array $cards;

    /** @var list<CompilerNote> */
    #[OrmHasMany(target: CompilerNote::class, mappedBy: 'board', owned: true)]
    public array $notes;

    public function __construct(bool $isActive)
    {
        $this->isActive = $isActive;
    }
}

#[Entity(table: 'compiler_cards')]
final class CompilerCard
{
    #[EntityId(generated: true)]
    public ?int $id = null;

    #[OrmBelongsTo]
    public CompilerBoard $board;

    public function __construct(CompilerBoard $board)
    {
        $this->board = $board;
    }
}

/** A board's note: a required text, a position and a JSON list of steps. */
#[Entity(table: 'compiler_notes')]
final class CompilerNote
{
    #[EntityId(generated: true)]
    public ?int $id = null;

    #[OrmBelongsTo]
    public CompilerBoard $board;

    public string $text;

    public int $position = 0;

    /** @var list<mixed>|null */
    public ?array $steps = null;

    public function __construct(CompilerBoard $board, string $text)
    {
        $this->board = $board;
        $this->text = $text;
    }
}

/** The notes a board's repeater edits; a note's steps are a repeater of their own. */
#[Resource(CompilerNote::class, slug: 'compiler-notes', labelSingular: 'Note')]
final class CompilerNotes extends VectorResource
{
    public function fields(): array
    {
        return [Id::make(), Text::make('text')->required(), Number::make('position'), Repeater::make('steps')->fields([Text::make('step')])];
    }
}
