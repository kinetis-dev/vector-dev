<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

final class Boolean extends Field
{
    public function type(): string
    {
        return 'boolean';
    }
}
