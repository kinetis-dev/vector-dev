<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Vector\Support\LabelResource;
use App\Vector\TagResource;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\BelongsTo;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Registry\SettingsCompiler;
use Kinetis\Vector\Settings\SettingsGroup;
use PHPUnit\Framework\TestCase;

/** Settings groups compiled into the descriptor's `settings` map. */
final class SettingsCompilerTest extends TestCase
{
    public function testAGroupCompilesItsFieldsInOrderWithRelationsResolvedToSlugs(): void
    {
        $shop = new class () extends SettingsGroup {
            public function name(): string { return 'shop'; }
            public function icon(): string { return 'mdi-store'; }
            public function fields(): array { return [Text::make('motto'), BelongsTo::to('featuredTag', TagResource::class)]; }
        };

        $groups = SettingsCompiler::compile([$shop::class], ['labels' => ['class' => LabelResource::class], 'tags' => ['class' => TagResource::class]]);

        self::assertSame(['shop'], array_keys($groups));
        self::assertSame(['name' => 'shop', 'class' => $shop::class, 'label' => 'Shop', 'icon' => 'mdi-store'], array_diff_key($groups['shop'], ['fields' => true]));
        self::assertSame(['motto', 'featuredTag'], array_column($groups['shop']['fields'], 'name'));
        self::assertTrue(array_is_list($groups['shop']['fields']), 'fields are a list, not keyed by name');
        self::assertSame('tags', $groups['shop']['fields'][1]['relation']['resource']);
    }

    public function testASettingsNameIsKebabCase(): void
    {
        $capital = new class () extends SettingsGroup {
            public function name(): string { return 'Shop'; }
            public function fields(): array { return []; }
        };
        $trailing = new class () extends SettingsGroup {
            public function name(): string { return 'shop_'; }
            public function fields(): array { return []; }
        };

        self::assertSame('Resource ' . $capital::class . ': has the settings name "Shop"; a settings name is lower-case kebab-case', $this->refusal([$capital::class]));
        self::assertSame('Resource ' . $trailing::class . ': has the settings name "shop_"; a settings name is lower-case kebab-case', $this->refusal([$trailing::class]));
    }

    public function testAConditionNamesAFieldOfTheSameGroup(): void
    {
        $group = new class () extends SettingsGroup {
            public function name(): string { return 'shop'; }
            public function fields(): array { return [Text::make('motto')->visibleWhen('openOnSundays', 'eq', true)]; }
        };

        self::assertSame(
            'Resource ' . $group::class . ', field "motto": has a visible condition on "openOnSundays", which is not a field of the same form',
            $this->refusal([$group::class]),
        );
    }

    /** @param list<class-string<SettingsGroup>> $classes */
    private function refusal(array $classes): string
    {
        try {
            SettingsCompiler::compile($classes, []);
        } catch (ResourceCompileException $e) {
            return $e->getMessage();
        }

        self::fail('The settings compiled.');
    }
}
