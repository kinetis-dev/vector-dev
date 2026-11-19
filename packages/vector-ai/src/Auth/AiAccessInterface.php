<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Auth;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\VectorAi\Feature;

/**
 * Who may use an AI feature, and who may configure AI (the `ai` settings
 * group and the provider keys). The default, VectorAiAccess, admits
 * everyone the Vector access policy admits; an application binds its own
 * on AppScope, as it binds VectorAccessInterface. One instance answers
 * for every request a worker handles, so it holds no request state.
 */
interface AiAccessInterface
{
    public function canUse(CurrentUserInterface $user, Feature $feature): bool;

    public function canConfigure(CurrentUserInterface $user): bool;
}
