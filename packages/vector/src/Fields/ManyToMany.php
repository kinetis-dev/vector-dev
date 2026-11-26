<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

final class ManyToMany extends RelationField
{
    #[\Override]
    public function type(): string
    {
        return 'manyToMany';
    }

    #[\Override]
    protected function hasTextBox(): bool
    {
        return true;
    }

    #[\Override]
    public function mapsColumn(): bool
    {
        return false;
    }
}
