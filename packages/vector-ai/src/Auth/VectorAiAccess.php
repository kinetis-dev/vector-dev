<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Auth;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\VectorAccessInterface;
use Kinetis\VectorAi\Feature;

/** The default AI policy: whoever may enter the admin may use and configure AI. */
final readonly class VectorAiAccess implements AiAccessInterface
{
    public function __construct(private VectorAccessInterface $vector) {}

    #[\Override]
    public function canUse(CurrentUserInterface $user, Feature $feature): bool
    {
        return $this->vector->canAccessVector($user);
    }

    #[\Override]
    public function canConfigure(CurrentUserInterface $user): bool
    {
        return $this->vector->canAccessVector($user);
    }
}
