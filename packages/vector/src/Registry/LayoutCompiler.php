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

        $field = static function (Field $f) use (&$fields, &$hasLayout): array {
            $fields[] = $f;
            $hasLayout = $hasLayout || $f->layoutSpan() !== null;

            return self::node('field', name: $f->name(), span: $f->layoutSpan());
        };

        $leaf = static function (Field|string $child) use ($field): array {
            return $child instanceof Field ? $field($child) : self::node('field', name: $child) + ['reference' => true];
        };

        $section = static function (Section $s) use ($leaf): array {
            $options = $s->options();

            return self::node('section', label: $s->label(), description: $options['description'], collapsible: $options['collapsible'], collapsed: $options['collapsed'], columns: $options['columns'], children: array_map($leaf, $s->items()));
        };

        foreach ($declared as $item) {
            if ($item instanceof Field) {
                $tree[] = $field($item);
            } elseif ($item instanceof Section) {
                $hasLayout = true;
                $tree[] = $section($item);
            } elseif ($item instanceof Tabs) {
                $hasLayout = true;
                $tabs = [];

                foreach ($item->tabs() as $tab) {
                    $tabs[] = self::node('tab', label: $tab['label'], columns: 2, children: array_map(
                        static fn (Field|string|Section $child): array => $child instanceof Section ? $section($child) : $leaf($child),
                        $tab['children'],
                    ));
                }

                if ($tabs === []) {
                    throw new ResourceCompileException("{$class}::fields() declares a Tabs with no tab.");
                }

                $tree[] = self::node('tabs', label: $item->label(), children: $tabs);
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
        $walk = static function (array $nodes, bool $nested) use (&$walk, &$placed, $compiled, $class): void {
            foreach ($nodes as $node) {
                if ($node['kind'] !== 'field') {
                    $walk($node['children'], true);

                    continue;
                }

                if (!isset($compiled[$node['name']])) {
                    throw ResourceCompileException::field($class, $node['name'], 'is placed in the layout but is not a field of the resource');
                }

                if ($nested) {
                    if (isset($placed[$node['name']])) {
                        throw ResourceCompileException::field($class, $node['name'], 'is placed in the layout twice');
                    }

                    $placed[$node['name']] = true;
                }
            }
        };
        $walk($tree, false);

        // a top-level field that a section or tab places moves there
        $tree = array_values(array_filter($tree, static fn (array $node): bool => $node['kind'] !== 'field' || !isset($placed[$node['name']])));

        $resolve = static function (array $nodes) use (&$resolve, $spans): array {
            return array_map(static function (array $node) use ($resolve, $spans): array {
                if ($node['kind'] === 'field') {
                    unset($node['reference']);
                    $node['span'] = $spans[$node['name']] ?? null;

                    return $node;
                }

                $node['children'] = $resolve($node['children']);

                return $node;
            }, $nodes);
        };
        $tree = $resolve($tree);

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
