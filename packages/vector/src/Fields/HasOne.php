<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

final class HasOne extends RelationField
{
    #[\Override]
    public static function to(string $name, string $resource, ?string $label = null): static
    {
        return parent::to($name, $resource, $label)->hideFromForms();
    }

    #[\Override]
    public function type(): string
    {
        return 'hasOne';
    }

    #[\Override]
    public function mapsColumn(): bool
    {
        return false;
    }
}
