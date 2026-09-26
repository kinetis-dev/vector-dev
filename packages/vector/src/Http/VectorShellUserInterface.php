<?php

declare(strict_types=1);

namespace Kinetis\Vector\Http;

/**
 * Optional: a CurrentUserInterface implementation that also answers a
 * display name lets the hosted shell greet the user without a request.
 */
interface VectorShellUserInterface
{
    public function displayName(): ?string;
}
