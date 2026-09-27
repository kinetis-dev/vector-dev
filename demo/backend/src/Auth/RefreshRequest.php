<?php

declare(strict_types=1);

namespace App\Auth;

use Kinetis\Validation\Constraints\MaxLength;
use Kinetis\Validation\Constraints\NotBlank;

final readonly class RefreshRequest
{
    public function __construct(
        #[NotBlank, MaxLength(128)]
        public string $refreshToken,
    ) {}
}
