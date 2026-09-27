<?php

declare(strict_types=1);

namespace App\Auth;

/** No cache that can count failures or hold refresh tokens: signing in is refused rather than left unthrottled. */
final class LoginUnavailableException extends \RuntimeException
{
    public static function because(\Throwable $previous): self
    {
        return new self('Signing in is unavailable: the application cache is not configured (REDIS_HOST).', 0, $previous);
    }
}
