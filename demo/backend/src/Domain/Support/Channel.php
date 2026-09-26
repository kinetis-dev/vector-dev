<?php

declare(strict_types=1);

namespace App\Domain\Support;

enum Channel: string
{
    case Email = 'email';
    case Chat = 'chat';
    case Phone = 'phone';
    case Web = 'web';
}
