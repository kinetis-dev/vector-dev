<?php

declare(strict_types=1);

namespace App\Auth;

use Kinetis\Validation\Constraints\Email;
use Kinetis\Validation\Constraints\MaxLength;
use Kinetis\Validation\Constraints\NotBlank;

final readonly class LoginRequest
{
    public function __construct(
        #[NotBlank, Email, MaxLength(255)]
        public string $email,
        #[NotBlank, MaxLength(1024)]
        public string $password,
    ) {}
}
