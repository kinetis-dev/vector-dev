<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\Field;
use Kinetis\Vector\Fields\Layout\Section;
use Kinetis\Vector\Fields\Layout\Tabs;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Registry\LayoutCompiler;
use PHPUnit\Framework\TestCase;

/** What fields() returned, split into the field list and the layout each view publishes. */
final class LayoutCompilerTest extends TestCase
{
    private const string OWNER = 'App\Vector\GadgetResource';

    public function testFieldsAloneHaveNoLayout(): void
    {
        $name = Text::make('name');
        $sku = Text::make('sku');

        self::assertSame(['fields' => [$name, $sku], 'tree' => null], LayoutCompiler::split([$name, $sku], self::OWNER));
        self::assertNull(LayoutCompiler::compile(null, [], [], self::OWNER));
    }

    public function testAColumnSpanAloneGivesALayout(): void
    {
        $split = LayoutCompiler::split([Text::make('name')->columnSpan('full'), Text::make('sku')], self::OWNER);

        self::assertSame([$this->field('name', 'full'), $this->field('sku')], $split['tree']);
        self::assertSame([$this->field('name'), $this->field('sku', 2)], LayoutCompiler::split([Text::make('name'), Text::make('sku')->columnSpan(2)], self::OWNER)['tree']);
    }

    public function testASectionOrTabsAloneGivesALayout(): void
    {
        $section = LayoutCompiler::split([Section::make('Main')->fields([Text::make('name')])], self::OWNER);
        self::assertSame([$this->section('Main', [$this->field('name')])], $section['tree']);

        $tabs = LayoutCompiler::split([Tabs::make()->tab('Main', [Text::make('name')])], self::OWNER);
        self::assertSame([$this->node('tabs', label: null, children: [$this->node('tab', label: 'Main', columns: 2, children: [$this->field('name')])])], $tabs['tree']);
    }

    public function testSplitKeepsDeclarationOrderAndMarksReferences(): void
    {
        $name = Text::make('name');
        $price = Text::make('price')->columnSpan(2);
        $stock = Text::make('stock');

        $split = LayoutCompiler::split([
            $name,
            Section::make('Pricing')->description('What it costs')->collapsed()->columns(3)->fields([$price, 'name']),
            Tabs::make('More')->tab('Stock', [$stock, Section::make('Notes')->fields(['notes'])])->tab('Other', ['code']),
        ], self::OWNER);

        self::assertSame([$name, $price, $stock], $split['fields']);
        self::assertSame([
            $this->field('name'),
            $this->node('section', label: 'Pricing', description: 'What it costs', collapsible: true, collapsed: true, columns: 3, children: [$this->field('price', 2), $this->reference('name')]),
            $this->node('tabs', label: 'More', children: [
                $this->node('tab', label: 'Stock', columns: 2, children: [$this->field('stock'), $this->section('Notes', [$this->reference('notes')])]),
                $this->node('tab', label: 'Other', columns: 2, children: [$this->reference('code')]),
            ]),
        ], $split['tree']);
    }

    public function testEachViewPublishesTheFieldsItShowsAndNoEmptyContainer(): void
    {
        $tree = LayoutCompiler::split([
            Section::make('Pricing')->fields([Text::make('price'), 'name']),
            Text::make('name'),
            Text::make('sku'),
            Tabs::make('More')
                ->tab('Stock', [Text::make('stock'), 'code', Section::make('Notes')->fields(['notes'])])
                ->tab('Hidden', ['secret']),
        ], self::OWNER)['tree'];

        $views = static fn (string ...$views): array => ['views' => $views];
        $compiled = [
            'price' => $views(Field::VIEW_INDEX, Field::VIEW_SHOW, Field::VIEW_CREATE, Field::VIEW_UPDATE),
            'name' => $views(Field::VIEW_INDEX, Field::VIEW_SHOW, Field::VIEW_CREATE, Field::VIEW_UPDATE),
            'sku' => $views(Field::VIEW_SHOW, Field::VIEW_UPDATE),
            'stock' => $views(Field::VIEW_CREATE, Field::VIEW_UPDATE),
            'code' => $views(Field::VIEW_INDEX, Field::VIEW_CREATE),
            'notes' => $views(Field::VIEW_SHOW),
            'secret' => $views(Field::VIEW_INDEX),
        ];

        $layout = LayoutCompiler::compile($tree, $compiled, ['price' => 2, 'name' => null], self::OWNER);

        // name moves into the section that places it; the Hidden tab and, on the form, the Notes section are left empty
        $pricing = $this->section('Pricing', [$this->field('price', 2), $this->field('name')]);
        self::assertSame([
            'form' => [
                $pricing,
                $this->field('sku'),
                $this->node('tabs', label: 'More', children: [$this->node('tab', label: 'Stock', columns: 2, children: [$this->field('stock'), $this->field('code')])]),
            ],
            'show' => [
                $pricing,
                $this->field('sku'),
                $this->node('tabs', label: 'More', children: [$this->node('tab', label: 'Stock', columns: 2, children: [$this->section('Notes', [$this->field('notes')])])]),
            ],
        ], $layout);
    }

    public function testEveryPlacedNameIsAFieldPlacedOnce(): void
    {
        $compiled = ['name' => ['views' => [Field::VIEW_SHOW]], 'sku' => ['views' => [Field::VIEW_SHOW]]];

        $unknown = LayoutCompiler::split([Section::make('A')->fields(['name']), Section::make('B')->fields(['ghost'])], self::OWNER)['tree'];
        self::assertSame(
            'Resource App\Vector\GadgetResource, field "ghost": is placed in the layout but is not a field of the resource',
            $this->refusal(static fn () => LayoutCompiler::compile($unknown, $compiled, [], self::OWNER)),
        );

        $twice = LayoutCompiler::split([Section::make('A')->fields(['sku', 'name']), Tabs::make()->tab('B', ['name'])], self::OWNER)['tree'];
        self::assertSame(
            'Resource App\Vector\GadgetResource, field "name": is placed in the layout twice',
            $this->refusal(static fn () => LayoutCompiler::compile($twice, $compiled, [], self::OWNER)),
        );
    }

    public function testPruneKeepsOnlyAdmittedFields(): void
    {
        $tree = [$this->field('name'), $this->section('A', [$this->field('sku'), $this->field('code')]), $this->section('B', [$this->field('sku')]), $this->field('price')];

        self::assertSame(
            [$this->field('name'), $this->section('A', [$this->field('code')]), $this->field('price')],
            LayoutCompiler::prune($tree, static fn (string $name): bool => $name !== 'sku'),
        );
    }

    private function refusal(\Closure $compile): string
    {
        try {
            $compile();
        } catch (ResourceCompileException $e) {
            return $e->getMessage();
        }

        self::fail('The layout compiled.');
    }

    /** @return array<string, mixed> */
    private function field(string $name, int|string|null $span = null): array
    {
        return $this->node('field', span: $span, name: $name);
    }

    /** @return array<string, mixed> */
    private function reference(string $name): array
    {
        return $this->field($name) + ['reference' => true];
    }

    /**
     * @param list<array<string, mixed>> $children
     * @return array<string, mixed>
     */
    private function section(string $label, array $children): array
    {
        return $this->node('section', label: $label, columns: 2, children: $children);
    }

    /**
     * @param list<array<string, mixed>>|null $children
     * @return array<string, mixed>
     */
    private function node(string $kind, ?string $label = null, ?string $description = null, bool $collapsible = false, bool $collapsed = false, ?int $columns = null, int|string|null $span = null, ?string $name = null, ?array $children = null): array
    {
        return ['kind' => $kind, 'label' => $label, 'description' => $description, 'collapsible' => $collapsible, 'collapsed' => $collapsed, 'columns' => $columns, 'span' => $span, 'name' => $name, 'children' => $kind === 'field' ? null : ($children ?? [])];
    }
}
