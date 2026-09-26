<?php

declare(strict_types=1);

namespace App\Auth;

use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Session\Middleware\SessionMiddleware;

/**
 * Loads the browser session for the admin API and the hosted shell, so
 * VectorAuthMiddleware can read a signed-in user from it. Runs first in
 * both groups.
 */
#[AsMiddlewareGroup('vector', priority: 70)]
#[AsMiddlewareGroup('vector-shell', priority: 70)]
final readonly class VectorSessionMiddleware extends SessionMiddleware {}
