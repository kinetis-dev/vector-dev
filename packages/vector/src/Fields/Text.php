<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

final class Text extends Field
{
    #[\Override]
    public function type(): string
    {
        return 'string';
    }
}
