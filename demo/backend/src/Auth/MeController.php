<?php

declare(strict_types=1);

namespace App\Auth;

use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\JwtUser;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\JsonResponse;
use Psr\Http\Message\ResponseInterface;

#[Middleware(JwtAuthMiddleware::class)]
final readonly class MeController
{
    public function __construct(
        private CurrentUserInterface $user,
        private JwtUser $claims,
    ) {}

    #[Get('/auth/me')]
    public function me(): ResponseInterface
    {
        return JsonResponse::create([
            'id' => $this->user->id(),
            'name' => $this->claims->claim('name'),
            'vector' => $this->claims->claim('vector') === true,
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
