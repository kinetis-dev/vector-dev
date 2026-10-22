<?php

declare(strict_types=1);

namespace Kinetis\Vector\Registry;

use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\Field;
use Kinetis\Vector\Fields\Layout\Section;
use Kinetis\Vector\Fields\Layout\Tabs;

/**
 * Separates what `fields()` returned into the flat field list the rest
 * of the compiler works on and the layout the descriptor publishes.
 *
 * The field list is every Field in declaration order, wherever it
 * sits. The layout is a tree of nodes: a top-level field keeps its
 * place; a field named inside a section or tab (by object or by name)
 * is placed there instead. Published per view — `form` (create and
 * update) and `show` — with the fields that view shows, and without a
 * section or tab left empty.
 *
 * @phpstan-type LayoutNode array{kind: 'section'|'tabs'|'tab'|'field', label: string|null, description: string|null, collapsible: bool, collapsed: bool, columns: int|null, span: int|string|null, name: string|null, children: list<array<string, mixed>>|null}
 */
final class LayoutCompiler
{
    /**
     * @param list<mixed> $declared what fields() returned
     * @return array{fields: list<Field>, tree: list<array<string, mixed>>|null}
     */
    public static function split(array $declared, string $class): array
    {
        $fields = [];
        $tree = [];
        $hasLayout = false;

        foreach ($declared as $item) {
            if ($item instanceof Field) {
                $hasLayout = $hasLayout || $item->layoutSpan() !== null;
                $tree[] = self::fieldNode($item, $fields);
            } elseif ($item instanceof Section) {
                $hasLayout = true;
                $tree[] = self::sectionNode($item, $fields);
            } elseif ($item instanceof Tabs) {
                $hasLayout = true;
                $tree[] = self::tabsNode($item, $fields, $class);
            } elseif (is_string($item)) {
                throw new ResourceCompileException("{$class}::fields() names \"{$item}\" at the top level; a field name belongs inside a section or a tab.");
            } else {
                throw new ResourceCompileException("{$class}::fields() returns " . get_debug_type($item) . '; it returns fields, sections and tabs.');
            }
        }

        return ['fields' => $fields, 'tree' => $hasLayout ? $tree : null];
    }

    /**
     * Resolves the names a layout places, then publishes it per view.
     *
     * @param list<array<string, mixed>>|null $tree
     * @param array<string, array<string, mixed>> $compiled field name => compiled field
     * @param array<string, int|string|null> $spans field name => the span its Field declared
     * @return array{form: list<array<string, mixed>>, show: list<array<string, mixed>>}|null
     */
    public static function compile(?array $tree, array $compiled, array $spans, string $class): ?array
    {
        if ($tree === null) {
            return null;
        }

        // every name placed inside a section or tab: known, and placed once
        $placed = [];
        self::place($tree, false, $compiled, $class, $placed);

        // a top-level field that a section or tab places moves there
        $tree = array_values(array_filter($tree, static fn (array $node): bool => $node['kind'] !== 'field' || !isset($placed[$node['name']])));
        $tree = self::resolve($tree, $spans);

        $inView = static fn (array $views): \Closure => static fn (string $name): bool => array_intersect($views, $compiled[$name]['views']) !== [];

        return [
            'form' => self::prune($tree, $inView([Field::VIEW_CREATE, Field::VIEW_UPDATE])),
            'show' => self::prune($tree, $inView([Field::VIEW_SHOW])),
        ];
    }

    /**
     * The tree with only the fields $keep admits, and no section, tab or
     * tab group left empty.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param \Closure(string): bool $keep
     * @return list<array<string, mixed>>
     */
    public static function prune(array $nodes, \Closure $keep): array
    {
        $kept = [];

        foreach ($nodes as $node) {
            if ($node['kind'] === 'field') {
                if ($keep($node['name'])) {
                    $kept[] = $node;
                }

                continue;
            }

            $node['children'] = self::prune($node['children'], $keep);

            if ($node['children'] !== []) {
                $kept[] = $node;
            }
        }

        return $kept;
    }

    /**
     * A Field declared in fields(), in a section or in a tab: collected
     * into the flat field list, and placed in the tree by name.
     *
     * @param list<Field> $fields
     * @return array<string, mixed>
     */
    private static function fieldNode(Field $field, array &$fields): array
    {
        $fields[] = $field;

        return self::node('field', name: $field->name(), span: $field->layoutSpan());
    }

    /**
     * A section or tab child: a Field, or the name of one declared elsewhere.
     *
     * @param list<Field> $fields
     * @return array<string, mixed>
     */
    private static function leafNode(Field|string $child, array &$fields): array
    {
        return $child instanceof Field ? self::fieldNode($child, $fields) : self::node('field', name: $child) + ['reference' => true];
    }

    /**
     * @param list<Field> $fields
     * @return array<string, mixed>
     */
    private static function sectionNode(Section $section, array &$fields): array
    {
        $options = $section->options();
        $children = [];

        foreach ($section->items() as $child) {
            $children[] = self::leafNode($child, $fields);
        }

        return self::node('section', label: $section->label(), description: $options['description'], collapsible: $options['collapsible'], collapsed: $options['collapsed'], columns: $options['columns'], children: $children);
    }

    /**
     * @param list<Field> $fields
     * @return array<string, mixed>
     */
    private static function tabsNode(Tabs $item, array &$fields, string $class): array
    {
        $tabs = [];

        foreach ($item->tabs() as $tab) {
            $children = [];

            foreach ($tab['children'] as $child) {
                $children[] = $child instanceof Section ? self::sectionNode($child, $fields) : self::leafNode($child, $fields);
            }

            $tabs[] = self::node('tab', label: $tab['label'], columns: 2, children: $children);
        }

        if ($tabs === []) {
            throw new ResourceCompileException("{$class}::fields() declares a Tabs with no tab.");
        }

        return self::node('tabs', label: $item->label(), children: $tabs);
    }

    /**
     * Records every name placed inside a section or tab, refusing one
     * that is not a field of the resource or is placed twice.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, array<string, mixed>> $compiled
     * @param array<array-key, true> $placed
     */
    private static function place(array $nodes, bool $nested, array $compiled, string $class, array &$placed): void
    {
        foreach ($nodes as $node) {
            if ($node['kind'] !== 'field') {
                self::place($node['children'], true, $compiled, $class, $placed);

                continue;
            }

            if (!isset($compiled[$node['name']])) {
                throw ResourceCompileException::field($class, $node['name'], 'is placed in the layout but is not a field of the resource');
            }

            if (!$nested) {
                continue;
            }

            if (isset($placed[$node['name']])) {
                throw ResourceCompileException::field($class, $node['name'], 'is placed in the layout twice');
            }

            $placed[$node['name']] = true;
        }
    }

    /**
     * Every field node with the span its Field declared, and no reference marker.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, int|string|null> $spans
     * @return array<int, array<string, mixed>>
     */
    private static function resolve(array $nodes, array $spans): array
    {
        return array_map(static function (array $node) use ($spans): array {
            if ($node['kind'] === 'field') {
                unset($node['reference']);
                $node['span'] = $spans[$node['name']] ?? null;

                return $node;
            }

            $node['children'] = self::resolve($node['children'], $spans);

            return $node;
        }, $nodes);
    }

    /**
     * One node, every key present.
     *
     * @param 'section'|'tabs'|'tab'|'field' $kind
     * @param list<array<string, mixed>>|null $children
     * @return array<string, mixed>
     */
    private static function node(
        string $kind,
        ?string $label = null,
        ?string $description = null,
        bool $collapsible = false,
        bool $collapsed = false,
        ?int $columns = null,
        int|string|null $span = null,
        ?string $name = null,
        ?array $children = null,
    ): array {
        return [
            'kind' => $kind,
            'label' => $label,
            'description' => $description,
            'collapsible' => $collapsible,
            'collapsed' => $collapsed,
            'columns' => $columns,
            'span' => $span,
            'name' => $name,
            'children' => $kind === 'field' ? null : ($children ?? []),
        ];
    }
}
