<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\VectorAi\Approvals\ApprovalStore;
use Kinetis\VectorAi\Assistant\Assistant;
use Kinetis\VectorAi\Auth\AiAccessInterface;
use Kinetis\VectorAi\Feature;

/**
 * `assistantWrites` on, and an access policy that lets everyone use the
 * assistant but nobody have it write: the model is given none of the
 * write tools, and an approval is 403 — even of a proposal stored before.
 */
final class AssistantWritesRefusedTest extends ApprovalsTestCase
{
    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(AiAccessInterface::class, new NoWritesAiAccess());
    }

    public function testTheModelIsGivenNoWriteTool(): void
    {
        $this->script([self::QUESTION => 'There are products#1.']);
        $this->turn();

        $tools = self::toolNames($this->calls()[0]);
        self::assertSame(Assistant::TOOLS, $tools);

        foreach (['vector_create', 'vector_update', 'vector_delete', 'vector_run_action'] as $write) {
            self::assertStringNotContainsString($write, self::sent($this->calls()[0]));
        }
    }

    public function testAProposalStoredBeforeCannotBeDecided(): void
    {
        $database = $this->app->get(VectorDatabase::class);
        self::assertInstanceOf(VectorDatabase::class, $database);
        $approval = new ApprovalStore($database)->propose(self::ACTOR_PREFIX . '01', null, 'c_test', 'vector_update', $this->update($this->stock + 5), 15);

        $this->decide($approval->publicId())->assertStatus(403);
        $this->decide($approval->publicId(), 'reject')->assertStatus(403);
        self::assertSame('pending', $this->row($approval->publicId())['status'] ?? null);
        self::assertSame($this->stock, $this->stockNow());
    }
}

/** Lets everyone use AI, except to have the assistant write. */
final readonly class NoWritesAiAccess implements AiAccessInterface
{
    #[\Override]
    public function canUse(CurrentUserInterface $user, Feature $feature): bool
    {
        return $feature !== Feature::AssistantWrites;
    }

    #[\Override]
    public function canConfigure(CurrentUserInterface $user): bool
    {
        return true;
    }
}
