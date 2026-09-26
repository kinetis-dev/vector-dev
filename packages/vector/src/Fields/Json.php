<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

final class Json extends Field
{
    public function type(): string
    {
        return 'json';
    }
}
