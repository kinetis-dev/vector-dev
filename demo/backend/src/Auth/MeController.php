<?php

declare(strict_types=1);

namespace App\Auth;

use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\JwtUser;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\CurrentUserInterface;

#[Middleware(JwtAuthMiddleware::class)]
final readonly class MeController
{
    public function __construct(
        private CurrentUserInterface $user,
        private JwtUser $claims,
    ) {}

    /** @return array{id: int|string, name: mixed, vector: bool} */
    #[Get('/auth/me')]
    public function me(): array
    {
        return [
            'id' => $this->user->id(),
            'name' => $this->claims->claim('name'),
            'vector' => $this->claims->claim('vector') === true,
        ];
    }
}
