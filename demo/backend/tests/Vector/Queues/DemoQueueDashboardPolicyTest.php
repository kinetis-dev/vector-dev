<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Auth\DemoActor;
use App\Auth\DemoQueueDashboardPolicy;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\ActorContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The demo policy's own refusals. Over HTTP a non-admin never reaches it —
 * DemoVectorAccess turns them away first — so its false branch is tested
 * here, directly.
 */
final class DemoQueueDashboardPolicyTest extends TestCase
{
    /** @return iterable<string, array{CurrentUserInterface}> */
    public static function nonAdmins(): iterable
    {
        yield 'a DemoActor without is_admin' => [new DemoActor(2, 'Editor', false)];
        yield 'an identity that is no DemoActor' => [new class implements CurrentUserInterface {
            public function id(): string
            {
                return 'service-7';
            }
        }];
    }

    #[DataProvider('nonAdmins')]
    public function testANonAdminMayViewButNotMutate(CurrentUserInterface $user): void
    {
        $policy = new DemoQueueDashboardPolicy();
        $actor = new ActorContext($user);

        self::assertTrue($policy->view($actor));
        self::assertFalse($policy->retry($actor));
        self::assertFalse($policy->forget($actor));
        self::assertFalse($policy->prune($actor));
        self::assertFalse($policy->clear($actor, 'default'));
    }

    public function testAnAdminMayDoEverything(): void
    {
        $policy = new DemoQueueDashboardPolicy();
        $actor = new ActorContext(new DemoActor(1, 'Admin', true));

        self::assertTrue($policy->view($actor));
        self::assertTrue($policy->retry($actor));
        self::assertTrue($policy->forget($actor));
        self::assertTrue($policy->prune($actor));
        self::assertTrue($policy->clear($actor, 'default'));
    }
}
