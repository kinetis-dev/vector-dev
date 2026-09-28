<?php

declare(strict_types=1);

namespace Kinetis\Vector\Auth;

/**
 * How the application hashes a password a `Password` field marked
 * `hashed()` receives. Vector never chooses an algorithm: the
 * application binds its own on AppScope — the one its login verifies
 * against — and a field may name another implementation for a table
 * hashed differently. Request-neutral: one instance answers for every
 * write a worker handles.
 */
interface PasswordHasherInterface
{
    public function hash(string $password): string;
}
