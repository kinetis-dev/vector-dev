<?php

declare(strict_types=1);

namespace App\Auth;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\ImpersonatedUserInterface;
use Kinetis\Vector\Http\VectorShellUserInterface;

/**
 * The identity the demo registers for Vector in both modes: built from
 * the token's claims in bearer mode, from the session in cookie mode,
 * from the users table in a worker. Carries who is impersonating it.
 */
final readonly class DemoActor implements CurrentUserInterface, VectorShellUserInterface, ImpersonatedUserInterface
{
    public function __construct(
        private int $id,
        private string $name,
        public bool $isAdmin,
        private ?int $impersonator = null,
        public ?string $impersonatorName = null,
    ) {}

    public function id(): int
    {
        return $this->id;
    }

    public function displayName(): string
    {
        return $this->name;
    }

    public function impersonatorId(): ?int
    {
        return $this->impersonator;
    }
}
