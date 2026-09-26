<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

final class Email extends Field
{
    public function type(): string
    {
        return 'email';
    }
}
