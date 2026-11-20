<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;
use Kinetis\VectorAi\Assistant\ProposingTool;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\ToolOutput;

/**
 * A change Vector's own permissions would refuse this user is refused
 * when it is proposed, not shown as a card the approval would only fail:
 * the resources without a policy of their own (products among them) here
 * let nobody create, update or force-delete, and let everyone delete. Nothing is stored
 * for a refused proposal; the one this user may make still is.
 */
final class ProposalPermissionsTest extends ApprovalsTestCase
{
    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(AllowAllPolicy::class, new NoWritesButDeletesPolicy());
    }

    public function testAnUpdateThisUserMayNotMakeIsRefusedAndNothingIsStored(): void
    {
        $run = $this->propose($this->update($this->stock + 5));

        self::assertSame(200, $run['status']);
        self::assertSame([], $run['approvals']);
        self::assertSame([], $this->rows());
        self::assertSame([ProposingTool::NOT_PERMITTED], self::toolErrors($this->calls()[1]));
        self::assertSame($this->stock, $this->stockNow());
    }

    public function testACreateThisUserMayNotMakeIsRefused(): void
    {
        $run = $this->propose(['resource' => 'products', 'values' => ['name' => 'Lamp']], 'vector_create');

        self::assertSame([], $run['approvals']);
        self::assertSame([], $this->rows());
        self::assertSame([ProposingTool::NOT_PERMITTED], self::toolErrors($this->calls()[1]));
    }

    public function testAnUpdateOfNoRecordTheyCanSeeIsRefused(): void
    {
        $run = $this->propose(['resource' => 'products', 'id' => '999999999', 'values' => ['stock' => 1]]);

        self::assertSame([], $run['approvals']);
        self::assertSame([ProposingTool::NOT_PERMITTED], self::toolErrors($this->calls()[1]));
    }

    public function testADeleteThisUserMayMakeIsStillProposed(): void
    {
        $approval = $this->proposed(['resource' => 'products', 'id' => (string) $this->product], 'vector_delete');

        self::assertSame('vector_delete', $approval['tool']);
        self::assertSame('pending', $this->row($approval['id'])['status'] ?? null);
    }

    public function testAForcedDeleteNeedsForceDeleteNotDelete(): void
    {
        $run = $this->propose(['resource' => 'products', 'id' => (string) $this->product, 'force' => true], 'vector_delete');

        self::assertSame(200, $run['status']);
        self::assertSame([], $run['approvals'], 'delete alone does not let a forced delete be proposed');
        self::assertSame([], $this->rows());
        self::assertSame([ProposingTool::NOT_PERMITTED], self::toolErrors($this->calls()[1]));
    }

    public function testAForcedDeleteOfATrashedRecordStillNeedsForceDelete(): void
    {
        $this->shop()->table('products')->where('id', '=', $this->product)->update(['deleted_at' => '2026-01-01 00:00:00']);

        try {
            $run = $this->propose(['resource' => 'products', 'id' => (string) $this->product, 'force' => true], 'vector_delete');
        } finally {
            $this->shop()->table('products')->where('id', '=', $this->product)->update(['deleted_at' => null]);
        }

        self::assertSame([], $run['approvals']);
        self::assertSame([ProposingTool::NOT_PERMITTED], self::toolErrors($this->calls()[1]));
    }

    /** @return list<string> the error texts of the tool results a recorded call carried */
    private static function toolErrors(RequestRecord $call): array
    {
        $errors = [];

        foreach ($call->messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $tool) {
                    $result = $tool->getResult();

                    if ($result instanceof ToolOutput && $result->isError()) {
                        $errors[] = $result->getText();
                    }
                }
            }
        }

        return $errors;
    }
}

/** Lets everyone read and delete, and nobody create, update or force-delete. */
final class NoWritesButDeletesPolicy extends ResourcePolicy
{
    #[\Override]
    public function forceDelete(CurrentUserInterface $user, array $record): bool
    {
        return false;
    }

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
