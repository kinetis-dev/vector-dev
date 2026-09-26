<?php

declare(strict_types=1);

namespace App\Auth;

use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Http\Responses\JsonResponse;
use Psr\Http\Message\ResponseInterface;

final readonly class LoginController
{
    private const int TTL_SECONDS = 3600;

    public function __construct(
        private JwtIssuer $issuer,
        private Credentials $credentials,
    ) {}

    #[Post('/auth/login')]
    public function login(#[Body] LoginRequest $data): ResponseInterface
    {
        $user = $this->credentials->verify($data->email, $data->password);

        if ($user === null) {
            return ErrorResponse::create(401, 'Invalid credentials.');
        }

        $token = $this->issuer->issue(
            (string) $user->id(),
            ['name' => $user->name(), 'vector' => $user->isAdmin()],
            ttlSeconds: self::TTL_SECONDS,
        );

        return JsonResponse::create([
            'accessToken' => $token,
            'tokenType' => 'Bearer',
            'expiresIn' => self::TTL_SECONDS,
            'user' => ['id' => $user->id(), 'name' => $user->name(), 'email' => $user->email()],
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
