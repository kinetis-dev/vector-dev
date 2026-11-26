<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

/**
 * An inverse to-many relation, shown as a count and a panel of the
 * related records. Rows edited inside the parent's form are a
 * {@see Repeater} in relationship mode.
 */
final class HasMany extends RelationField
{
    #[\Override]
    public static function to(string $name, string $resource, ?string $label = null): static
    {
        return parent::to($name, $resource, $label)->hideFromForms();
    }

    #[\Override]
    public function type(): string
    {
        return 'hasMany';
    }

    #[\Override]
    public function mapsColumn(): bool
    {
        return false;
    }
}
