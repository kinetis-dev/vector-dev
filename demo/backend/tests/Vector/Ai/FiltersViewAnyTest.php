<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Vector\UserPolicy;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\ResourcePolicy;

/**
 * A user who may not list a resource may not ask for a filter of it:
 * 403 before the model is asked anything, though the feature is on and
 * the resource may be sent to a model.
 */
final class FiltersViewAnyTest extends FiltersTestCase
{
    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(UserPolicy::class, new RefusingViewAnyPolicy());
    }

    public function testAResourceTheUserMayNotListIsForbiddenAndNothingIsSent(): void
    {
        $this->script(['the admins' => self::answer([['field' => 'isAdmin', 'op' => 'eq', 'value' => 'true']])]);

        $this->ask('users', 'the admins')->assertStatus(403)->assertJsonPath('error', 'This account may not list Users.');

        self::assertSame([], $this->calls());
    }
}

/** Lets nobody list the resource. */
final class RefusingViewAnyPolicy extends ResourcePolicy
{
    #[\Override]
    public function viewAny(CurrentUserInterface $user): bool
    {
        return false;
    }
}
