<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Auth\DemoActor;
use App\Vector\UserPolicy;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\ResourcePolicy;
use Kinetis\VectorAi\Assistant\Assistant;
use Kinetis\VectorAi\Governance\ModelBoundary;

/**
 * A resource the user may not list is not one the model is told of,
 * though it may be sent to a model and the user sees its fields: the
 * system prompt names neither its label, its path nor its record, and
 * its tools refuse it as they refuse one kept from the model.
 */
final class AssistantViewAnyTest extends AssistantTestCase
{
    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(UserPolicy::class, new RefusingUsersViewAnyPolicy());
    }

    public function testAResourceTheUserMayNotListIsNotSendable(): void
    {
        $scope = $this->app->createRequestScope();
        $scope->instance(CurrentUserInterface::class, new DemoActor(1, 'Admin', true));

        try {
            $boundary = $scope->get(ModelBoundary::class);
            $assistant = $scope->get(Assistant::class);
            self::assertInstanceOf(ModelBoundary::class, $boundary);
            self::assertInstanceOf(Assistant::class, $assistant);

            self::assertNull($boundary->sendable('users'));
            self::assertNotNull($boundary->sendable('products'), 'a resource the user may list still is');
            self::assertSame(['error' => ModelBoundary::UNAVAILABLE], $boundary->toolResult('vector_describe', ['resource' => 'users'], ['fields' => []]));

            $instructions = $assistant->instructions('/r/users/3', 'users', '3');
            self::assertStringContainsString('The page they are on: (none)', $instructions);
            self::assertStringNotContainsString('Users', $instructions);
            self::assertStringNotContainsString('/r/users', $instructions);

            self::assertStringContainsString('"resourceLabel":"Products"', $assistant->instructions('/r/products', 'products', null));
        } finally {
            $scope->dispose();
        }
    }
}

/** Lets nobody list users. */
final class RefusingUsersViewAnyPolicy extends ResourcePolicy
{
    #[\Override]
    public function viewAny(CurrentUserInterface $user): bool
    {
        return false;
    }
}
