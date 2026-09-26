<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

final class HasOne extends RelationField
{
    public static function to(string $name, string $resource, ?string $label = null): static
    {
        return parent::to($name, $resource, $label)->hideFromForms();
    }

    public function type(): string
    {
        return 'hasOne';
    }

    public function mapsColumn(): bool
    {
        return false;
    }
}
