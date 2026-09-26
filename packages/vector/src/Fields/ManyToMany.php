<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

final class ManyToMany extends RelationField
{
    public function type(): string
    {
        return 'manyToMany';
    }

    public function mapsColumn(): bool
    {
        return false;
    }
}
