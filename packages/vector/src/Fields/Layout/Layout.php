<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields\Layout;

use Kinetis\Vector\Fields\Field;

/**
 * A layout object `fields()` may return beside fields: a section or a
 * group of tabs. Plain compile-time data, like a field. Layout groups
 * fields on the form and the show page and nothing else: it never
 * changes what is validated, serialized, filtered or written. The
 * compiler flattens it back to the field list and records the grouping
 * as the descriptor's `layout`.
 *
 * A child is a Field, or the name of a field declared elsewhere in
 * `fields()`, which the layout then places here.
 */
abstract class Layout
{
    /**
     * @param list<mixed> $children
     * @return list<Field|string|Section>
     */
    protected static function children(array $children, bool $sections, string $owner): array
    {
        $checked = [];

        foreach ($children as $child) {
            $allowed = $child instanceof Field || (is_string($child) && $child !== '') || ($sections && $child instanceof Section);

            if (!$allowed) {
                throw new \InvalidArgumentException("{$owner} holds " . get_debug_type($child) . '; a child is a field, a field name' . ($sections ? ' or a section' : '') . '.');
            }

            $checked[] = $child;
        }

        return $checked;
    }
}
