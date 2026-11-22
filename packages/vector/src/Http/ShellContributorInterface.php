<?php

declare(strict_types=1);

namespace Kinetis\Vector\Http;

use Kinetis\Http\CurrentUserInterface;

/**
 * A package's contribution to the admin shell's `capabilities`: what it
 * offers this user, as a JSON object. Discovered through
 * #[ShellContributor(name: ...)] and resolved from the request scope on
 * every GET /vector/schema, so it may depend on request-scoped services;
 * the answer is part of the descriptor's ETag. It must not block, and it
 * must never carry a secret: the descriptor is sent to the browser.
 */
interface ShellContributorInterface
{
    /**
     * @return array<string, mixed> published as `capabilities.<name>`
     */
    public function capabilities(CurrentUserInterface $user): array;
}
