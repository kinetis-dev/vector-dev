<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Auth\DemoActor;
use App\Vector\UserPolicy;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\VectorAi\Failure\AiFailure;
use Kinetis\VectorAi\Providers\ResolvedProvider;
use Kinetis\VectorAi\Writing\WritingAssistant;
use Kinetis\VectorAi\Writing\WritingOperation;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;

/**
 * A field the user may not write is refused though it is a text field
 * the model may be sent: the resources without a policy of their own
 * (orders among them) here let nobody create, and let nobody update any
 * record; so a request with no record (the create form) and one with a
 * record (the update form, whose recordPermissions deny update) are both
 * 403, and the model is asked nothing. A field this user's viewField
 * hides (the users' `name`, for one actor) is no field to them: 404, and
 * the model is asked nothing. A provider's failure is its category, with
 * its usage row.
 */
final class WritingPermissionsTest extends WritingTestCase
{
    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(AllowAllPolicy::class, new ReadOnlyPolicy());
        $app->instance(UserPolicy::class, new HidingNamePolicy(self::ACTOR_PREFIX . '08'));
    }

    public function testAFieldTheUsersViewFieldHidesIsNoFieldToThem(): void
    {
        $this->scriptWriting(['Ada Lovelace' => 'Ada King']);

        $this->write('users', 'name', ['operation' => 'rewrite', 'text' => 'Ada Lovelace'], 8)
            ->assertStatus(404)->assertJsonPath('error', 'Users has no field name.');
        self::assertSame([], $this->calls());

        // the same field, to whom it is not hidden, is helped
        $this->write('users', 'name', ['operation' => 'rewrite', 'text' => 'Ada Lovelace'], 9)->assertOk()->assertJsonPath('text', 'Ada King');
    }

    public function testNeitherTheCreateNorTheUpdateFormMayBeHelped(): void
    {
        $this->scriptWriting(['Leave it at the door.' => 'Leave at the door.']);

        $this->write('orders', 'notes', ['operation' => 'shorten', 'text' => 'Leave it at the door.'])
            ->assertStatus(403)->assertJsonPath('error', 'This account may not create Orders.');
        $this->write('orders', 'notes', ['operation' => 'shorten', 'text' => 'Leave it at the door.', 'recordId' => $this->orderId()])
            ->assertStatus(403)->assertJsonPath('error', 'This account may not update this record of Orders.');

        self::assertSame([], $this->calls());
    }

    public function testAProviderFailureIsItsCategoryAndStillOneUsageRow(): void
    {
        $actor = self::ACTOR_PREFIX . '07';
        $scope = $this->app->createRequestScope();

        try {
            $scope->instance(CurrentUserInterface::class, new DemoActor((int) $actor, 'Test', true));
            $resources = $scope->get(ResourceService::class);
            $writing = $scope->get(WritingAssistant::class);
            self::assertInstanceOf(ResourceService::class, $resources);
            self::assertInstanceOf(WritingAssistant::class, $writing);
            $notes = $resources->resource('orders')->field('notes') ?? self::fail('orders.notes');

            try {
                $writing->write(new ResolvedProvider(new RefusingProvider(), 'test-fake', 'fake', 100), $notes, WritingOperation::Shorten, 'Leave it at the door.', 'plain', null, null);
                self::fail('the provider\'s failure did not propagate');
            } catch (AiFailure $e) {
                self::assertStringNotContainsString('quoted', $e->getMessage(), 'the provider\'s words are not the admin\'s');
                $category = $e->category->value;
            }
        } finally {
            $scope->dispose();
        }

        $rows = $this->vectorQuery()->table('vector_ai_usage')->where('actor_id', '=', $actor)->get();
        self::assertCount(1, $rows);
        self::assertSame('writing', $rows[0]['feature']);
        self::assertSame('failed:' . $category, $rows[0]['outcome']);
    }
}

/** Lets anyone read, and nobody create or update. */
final class ReadOnlyPolicy extends ResourcePolicy
{
    #[\Override]
    public function create(CurrentUserInterface $user): bool
    {
        return false;
    }

    #[\Override]
    public function update(CurrentUserInterface $user, array $record): bool
    {
        return false;
    }
}

/** The users' policy with `name` hidden from one actor, and everything else allowed. */
final class HidingNamePolicy extends ResourcePolicy
{
    public function __construct(private readonly string $hiddenFrom) {}

    #[\Override]
    public function viewField(CurrentUserInterface $user, string $field): bool
    {
        return $field !== 'name' || (string) $user->id() !== $this->hiddenFrom;
    }
}

/** A provider whose chat fails as a refusing provider does, quoting the prompt. */
final class RefusingProvider extends FakeAIProvider
{
    #[\Override]
    public function chat(Message ...$messages): ProviderResponse
    {
        throw new ProviderException('The provider quoted the prompt: Leave it at the door.');
    }
}
