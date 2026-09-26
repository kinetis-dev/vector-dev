<?php

declare(strict_types=1);

namespace Kinetis\Vector\Auth;

use Kinetis\Http\CurrentUserInterface;

/**
 * How the application lets one admin act as another. Vector owns the
 * endpoints, the authorization hook and the log entries; the
 * application owns the credential — a re-issued token carrying an
 * `impersonator` claim in bearer mode, a swapped session in cookie mode
 * — and answers with what the client must do next.
 */
interface ImpersonationInterface
{
    /** Whether this user may impersonate at all; the target is checked by start(). */
    public function canImpersonate(CurrentUserInterface $user): bool;

    /**
     * Switches the credential to the target user, or throws a
     * ForbiddenOperationException. Answers what the client needs: for
     * bearer mode a new `accessToken`, for cookie mode `reload: true`.
     *
     * @return array<string, mixed>
     */
    public function start(CurrentUserInterface $actor, string $targetId): array;

    /**
     * Restores the impersonator's own credential.
     *
     * @return array<string, mixed>
     */
    public function stop(CurrentUserInterface $impersonated): array;

    /** @return array{id: int|string, name: string|null}|null the target as it will appear, or null when it does not exist */
    public function describe(string $targetId): ?array;
}
