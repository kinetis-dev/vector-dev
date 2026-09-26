<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

final class Date extends Field
{
    public function type(): string
    {
        return 'date';
    }
}
