<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Vector\Ai\DemoAiReplies;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\VectorAi\Approvals\Approval;
use Kinetis\VectorAi\Approvals\ApprovalId;
use Kinetis\VectorAi\Approvals\ApprovalStatus;
use Kinetis\VectorAi\Approvals\ApprovalStore;
use Kinetis\VectorAi\Assistant\Assistant;
use Kinetis\VectorAi\Assistant\ProposingTool;
use Kinetis\VectorAi\Governance\ModelBoundary;
use Kinetis\VectorAi\Http\ApprovalController;
use NeuronAI\Chat\Messages\ToolResultMessage;

/**
 * The assistant proposes a write, which changes nothing until the admin
 * approves it; then exactly the stored arguments run, as
 * the admin, recorded `origin = 'assistant'`. Decisions are idempotent
 * (409), bound to the actor and the impersonator (404), and expire (410).
 */
final class AssistantApprovalsTest extends ApprovalsTestCase
{
    public function testAProposalStoresAPendingRowAndWritesNothing(): void
    {
        $before = hrtime(true);
        $approval = $this->proposed($this->update($this->stock + 5));

        self::assertSame('approval', $approval['type']);
        self::assertMatchesRegularExpression('/^apr_[a-z2-7]{26}$/', $approval['id']);
        self::assertSame('vector_update', $approval['tool']);
        self::assertSame(self::keySorted($this->update($this->stock + 5)), self::keySorted($approval['arguments']));
        self::assertSame('products', $approval['resource']);
        self::assertSame((string) $this->product, $approval['recordId']);
        $expires = new \DateTimeImmutable($approval['expiresAt']);
        self::assertEqualsWithDelta(time() + 15 * 60, $expires->getTimestamp(), 30, 'approvalMinutes defaults to 15');

        $row = $this->row($approval['id']);
        self::assertNotNull($row);
        self::assertSame('pending', $row['status']);
        self::assertSame(self::ACTOR_PREFIX . '01', $row['actor_id']);
        self::assertNull($row['impersonator_id']);
        self::assertSame('c_test', $row['conversation_id']);
        self::assertSame('vector_update', $row['tool']);
        self::assertSame(hash('sha256', json_encode((object) $this->update($this->stock + 5), JSON_THROW_ON_ERROR)), $row['arguments_hash']);
        self::assertNull($row['result']);

        // the model was told it waits, and nothing was written
        $results = self::toolResults($this->calls()[1]);
        self::assertSame([sprintf(ProposingTool::PROPOSED, $approval['id'])], $results);
        self::assertSame($this->stock, $this->stockNow());
        self::assertSame([], $this->auditRows(), 'a proposal writes no audit entry');
        self::assertGreaterThan($before, hrtime(true));
    }

    public function testApproveWritesAsTheUserWithTheAssistantOrigin(): void
    {
        $approval = $this->proposed($this->update($this->stock + 5));

        $answer = $this->decide($approval['id'])->assertOk()->json();
        self::assertIsArray($answer);
        self::assertSame('executed', $answer['status']);
        self::assertSame($this->stock + 5, $answer['result']['data']['stock']);
        self::assertSame($this->stock + 5, $this->stockNow());

        $row = $this->row($approval['id']);
        self::assertNotNull($row);
        self::assertSame('executed', $row['status']);
        self::assertNotNull($row['decided_at']);
        self::assertNotNull($row['executed_at']);

        // History shows it: the audit endpoint, as the admin reads it
        $trail = $this->client->get("/vector/r/products/{$this->product}/audit", headers: $this->aiHeaders())->assertOk()->json();
        self::assertIsArray($trail);
        $latest = $trail['data'][0];
        self::assertSame('update', $latest['event']);
        self::assertSame(self::ACTOR_PREFIX . '01', $latest['actorId']);
        self::assertSame('assistant', $latest['origin']);
        self::assertSame(self::keySorted(['from' => $this->stock, 'to' => $this->stock + 5]), self::keySorted($latest['changes']['stock']));

        // one usage row for the approval: who, the feature and the outcome; no tokens
        $usage = array_values(array_filter($this->usageRows(1), static fn (array $r): bool => $r['feature'] === 'assistant-writes'));
        self::assertCount(1, $usage);
        self::assertSame('approval:executed', $usage[0]['outcome']);
        self::assertSame(0, (int) $usage[0]['input_tokens']);
    }

    public function testASecondApproveIs409WithTheStoredResult(): void
    {
        $approval = $this->proposed($this->update($this->stock + 5));
        $first = $this->decide($approval['id'])->assertOk()->json();
        self::assertIsArray($first);

        $again = $this->decide($approval['id'])->assertStatus(409)->json();
        self::assertIsArray($again);
        self::assertSame('executed', $again['status']);
        self::assertSame(self::keySorted($first['result']), self::keySorted($again['result']));
        self::assertCount(1, $this->auditRows(), 'nothing ran twice');
        $this->decide($approval['id'], 'reject')->assertStatus(409)->assertJsonPath('status', 'executed');
    }

    public function testAnotherUserCannotDecideIt(): void
    {
        $approval = $this->proposed($this->update($this->stock + 5));

        $this->decide($approval['id'], actor: 2)->assertNotFound();
        $this->decide($approval['id'], 'reject', actor: 2)->assertNotFound();
        $this->decide('apr_' . str_repeat('a', 26))->assertNotFound();
        $this->decide('not-an-id')->assertNotFound();

        self::assertSame('pending', $this->row($approval['id'])['status'] ?? null);
        self::assertSame($this->stock, $this->stockNow());
    }

    public function testAnExpiredProposalIs410AndMarkedExpired(): void
    {
        $approval = $this->proposed($this->update($this->stock + 5));
        $this->vectorQuery()->table('vector_ai_approvals')->where('id', '=', substr($approval['id'], 4))->update(['expires_at' => '2026-01-01 00:00:00.000000']);

        $this->decide($approval['id'])->assertStatus(410);
        self::assertSame('expired', $this->row($approval['id'])['status'] ?? null);
        $this->decide($approval['id'])->assertStatus(410);
        $this->decide($approval['id'], 'reject')->assertStatus(410);
        self::assertSame($this->stock, $this->stockNow());
    }

    public function testAnImpersonationMismatchIs404EitherWay(): void
    {
        // proposed as themselves: an impersonation of them cannot decide it
        $own = $this->proposed($this->update($this->stock + 5));
        $this->decide($own['id'], headers: $this->impersonated(1, 1))->assertNotFound();
        self::assertSame('pending', $this->row($own['id'])['status'] ?? null);

        // proposed while impersonated: neither they themselves nor another impersonator can decide it
        $impersonated = $this->proposed($this->update($this->stock + 6), headers: $this->impersonated(1, 1));
        self::assertSame('1', $this->row($impersonated['id'])['impersonator_id'] ?? null);
        $this->decide($impersonated['id'])->assertNotFound();
        $this->decide($impersonated['id'], headers: $this->impersonated(1, 2))->assertNotFound();
        self::assertSame($this->stock, $this->stockNow());

        // the same impersonation can
        $this->decide($impersonated['id'], 'reject', headers: $this->impersonated(1, 1))->assertOk()->assertJsonPath('status', 'rejected');
    }

    public function testRejectDeclinesItAndApproveAfterIs409(): void
    {
        $approval = $this->proposed($this->update($this->stock + 5));

        $this->decide($approval['id'], 'reject')->assertOk()->assertJsonPath('status', 'rejected');
        $after = $this->decide($approval['id'])->assertStatus(409)->json();
        self::assertIsArray($after);
        self::assertSame('rejected', $after['status']);
        self::assertArrayNotHasKey('result', $after);
        self::assertSame($this->stock, $this->stockNow());
        self::assertSame([], $this->auditRows());
    }

    public function testApproveIgnoresTheRequestBody(): void
    {
        $approval = $this->proposed($this->update($this->stock + 5));

        // a body naming other arguments, another tool, another record: none of it is read
        $this->decide($approval['id'], body: [
            'tool' => 'vector_delete',
            'arguments' => $this->update($this->stock + 500),
            'values' => ['stock' => $this->stock + 500],
            'id' => '1',
        ])->assertOk()->assertJsonPath('status', 'executed');

        self::assertSame($this->stock + 5, $this->stockNow());
    }

    public function testAMalformedProposalGetsTheDispatchersErrorsAndStoresNothing(): void
    {
        $run = $this->propose(['resource' => 'products', 'id' => (string) $this->product, 'stock' => 3]);

        self::assertSame([], $run['approvals']);
        self::assertSame([], $this->rows());
        $errors = json_decode(self::toolResults($this->calls()[1])[0], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($errors);
        // the MCP dispatcher's own violations, in its order: the missing argument, then the unexpected one
        self::assertSame([['values'], ['stock']], array_column($errors['errors'], 'path'));
        self::assertSame('required', $errors['errors'][0]['code']);
    }

    public function testArgumentsNestedTooDeepToBeReadBackAreRefusedAndNothingIsStored(): void
    {
        // one level past what a stored row is read back with: approving or rejecting it could never read it
        $value = 7;

        for ($i = 0; $i < ApprovalStore::MAX_DEPTH; $i++) {
            $value = [$value];
        }

        $run = $this->propose(['resource' => 'products', 'id' => (string) $this->product, 'values' => ['stock' => $value]]);

        self::assertSame(200, $run['status']);
        self::assertSame([], $run['approvals']);
        self::assertSame([], $this->rows());
        self::assertSame([ProposingTool::TOO_DEEP], self::toolErrors($this->calls()[1]));
    }

    public function testAResourceOrFieldKeptFromTheModelIsRefused(): void
    {
        $profiles = $this->propose(['resource' => 'profiles', 'id' => '1', 'values' => ['name' => 'Ada']]);
        self::assertSame([], $profiles['approvals']);
        self::assertSame([ModelBoundary::UNAVAILABLE], self::toolErrors($this->calls()[1]));

        // users send their name, never their email (meta ai.send false)
        $email = $this->propose(['resource' => 'users', 'id' => '1', 'values' => ['email' => 'x@example.com']]);
        self::assertSame([], $email['approvals']);
        self::assertSame([ProposingTool::FIELD_REFUSED], self::toolErrors($this->calls()[1]));

        self::assertSame([], $this->rows());
    }

    public function testWritesOffGivesTheModelNoWriteTool(): void
    {
        $this->settings(['assistantWrites' => false]);
        $this->script([self::QUESTION => 'There are products#1.']);
        $this->turn();

        $tools = self::toolNames($this->calls()[0]);
        self::assertSame(Assistant::TOOLS, $tools);
        self::assertStringNotContainsString('vector_update', self::sent($this->calls()[0]));
        self::assertStringContainsString('You cannot change data: you have no tool that writes', (string) $this->calls()[0]->systemPrompt?->getContent());
    }

    public function testWritesOnGivesTheModelTheFourProposingTools(): void
    {
        $this->script([self::QUESTION => 'There are products#1.']);
        $this->turn();

        $tools = self::toolNames($this->calls()[0]);
        self::assertSame([...Assistant::TOOLS, ...ProposingTool::TOOLS], $tools);
        self::assertStringContainsString('only propose a change', (string) $this->calls()[0]->systemPrompt?->getContent());
    }

    public function testApproveIs403OnceWritesAreOff(): void
    {
        $approval = $this->proposed($this->update($this->stock + 5));
        $this->settings(['assistantWrites' => false]);

        $this->decide($approval['id'])->assertStatus(403);
        $this->decide($approval['id'], 'reject')->assertStatus(403);
        self::assertSame('pending', $this->row($approval['id'])['status'] ?? null);
        self::assertSame($this->stock, $this->stockNow());
    }

    public function testAFailedExecutionIsStoredWithTheToolsRefusal(): void
    {
        // a stock the field's rules refuse: the proposal is well-formed, the write is not
        $approval = $this->proposed(['resource' => 'products', 'id' => (string) $this->product, 'values' => ['stock' => 'many']]);

        $answer = $this->decide($approval['id'])->assertOk()->json();
        self::assertIsArray($answer);
        self::assertSame('failed', $answer['status']);
        self::assertIsString($answer['result']['error']);
        self::assertSame('failed', $this->row($approval['id'])['status'] ?? null);
        self::assertSame($this->stock, $this->stockNow());
    }

    public function testTheModelIsToldTheOutcomeFromTheRow(): void
    {
        $approval = $this->proposed($this->update($this->stock + 5));
        $this->decide($approval['id'])->assertOk();

        $this->script([Assistant::OUTCOME_PROMPT => 'Done: the stock is updated.']);
        $turn = $this->turn(body: [
            'conversationId' => 'c_test',
            'messages' => [
                ['role' => 'user', 'content' => self::QUESTION],
                ['role' => 'assistant', 'content' => sprintf(self::PROPOSED_ANSWER, $this->product)],
                ['role' => 'approval', 'id' => $approval['id']],
            ],
        ]);

        self::assertSame(200, $turn['status']);
        self::assertSame('Done: the stock is updated.', self::text($turn['frames']));
        $sent = self::sent($this->calls()[0]);
        self::assertStringContainsString("Approval {$approval['id']}, your proposed vector_update to products#{$this->product}: approved and executed.", $sent);
        self::assertStringContainsString('"stock":' . ($this->stock + 5), $sent);
        self::assertSame($this->stock + 5, $this->stockNow());
        self::assertCount(1, $this->auditRows(), 'replaying the conversation wrote nothing more');

        // another user's approval id tells the model nothing of it
        $this->script([Assistant::OUTCOME_PROMPT => 'Nothing to report.']);
        $this->turn(body: ['conversationId' => 'c_test', 'messages' => [['role' => 'approval', 'id' => $approval['id']]]], actor: 2);
        self::assertStringContainsString('there is no such proposal', self::sent($this->calls()[0]));
        self::assertStringNotContainsString('"stock"', self::sent($this->calls()[0]));
    }

    public function testTheJsonAnswerCarriesTheProposals(): void
    {
        $this->script([self::QUESTION => self::rounds([[['name' => 'vector_update', 'arguments' => $this->update($this->stock + 5)]]], 'Proposed.')]);
        $turn = $this->turn(headers: ['Accept' => 'application/json']);

        self::assertSame(200, $turn['status']);
        $answer = json_decode($turn['body'], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        // a proposal waiting for the admin is no tool error
        self::assertSame([['name' => 'vector_update', 'ok' => true]], $answer['toolCalls']);
        self::assertCount(1, $answer['approvals']);
        self::assertSame('vector_update', $answer['approvals'][0]['tool']);
        self::assertSame('pending', $this->row($answer['approvals'][0]['id'])['status'] ?? null);
    }

    public function testProposalsCountAgainstTheToolBudget(): void
    {
        $this->settings(['maxToolCallsPerRun' => 1, 'assistantWrites' => true]);
        $call = ['name' => 'vector_update', 'arguments' => $this->update($this->stock + 5)];
        $this->script([self::QUESTION => self::rounds([[$call, $call]], 'Proposed one.')]);
        $turn = $this->turn();

        self::assertCount(1, self::ofType($turn['frames'], 'data-approval'));
        self::assertContains(\Kinetis\VectorAi\Assistant\GovernedTool::EXHAUSTED, self::toolErrors($this->calls()[1]));
    }

    public function testTheDemosRestockRequestProposesAndAcknowledges(): void
    {
        $demo = DemoAiReplies::replies();
        $turns = [$demo->turn(DemoAiReplies::RESTOCK, 0), $demo->turn(DemoAiReplies::RESTOCK, 1), $demo->turn(DemoAiReplies::RESTOCK, 2)];
        self::assertNotContains(null, $turns);
        self::assertNull($demo->turn(DemoAiReplies::RESTOCK, 3));
        $this->script([DemoAiReplies::RESTOCK => $turns]);

        $frames = $this->turn([DemoAiReplies::RESTOCK])['frames'];
        $approvals = self::ofType($frames, 'data-approval');
        self::assertCount(1, $approvals);
        self::assertSame(self::keySorted(['resource' => 'products', 'id' => DemoAiReplies::RESTOCKED, 'values' => ['stock' => 42]]), self::keySorted($approvals[0]['data']['arguments']));
        self::assertSame(DemoAiReplies::RESTOCK_ANSWER, self::text($frames));
        // the run read the record before proposing: the card shows its stock before the change
        self::assertSame('vector_get', self::ofType($frames, 'tool-input-start')[0]['toolName']);

        $this->decide($approvals[0]['data']['id'], 'reject')->assertOk();
        $this->script([Assistant::OUTCOME_PROMPT => $demo->replyTo(Assistant::OUTCOME_PROMPT) ?? '']);
        $after = $this->turn(body: ['conversationId' => 'c_test', 'messages' => [['role' => 'approval', 'id' => $approvals[0]['data']['id']]]]);
        self::assertSame(DemoAiReplies::DECIDED_ANSWER, self::text($after['frames']));
    }

    public function testTheDemosShippingRequestProposesTheOrderShippedAndChangesNothing(): void
    {
        $order = $this->shop()->table('orders')->select('status')->where('id', '=', (int) DemoAiReplies::SHIPPED)->first();
        self::assertSame('paid', is_array($order) ? $order['status'] : null, 'the seeded order is paid');
        $demo = DemoAiReplies::replies();
        $turns = [$demo->turn(DemoAiReplies::SHIP, 0), $demo->turn(DemoAiReplies::SHIP, 1), $demo->turn(DemoAiReplies::SHIP, 2)];
        self::assertNotContains(null, $turns);
        self::assertNull($demo->turn(DemoAiReplies::SHIP, 3));
        $this->script([DemoAiReplies::SHIP => $turns]);

        $frames = $this->turn([DemoAiReplies::SHIP])['frames'];
        $approvals = self::ofType($frames, 'data-approval');
        self::assertCount(1, $approvals);
        self::assertSame(self::keySorted(['resource' => 'orders', 'id' => DemoAiReplies::SHIPPED, 'values' => ['status' => 'shipped']]), self::keySorted($approvals[0]['data']['arguments']));
        self::assertSame(DemoAiReplies::SHIP_ANSWER, self::text($frames));
        self::assertSame('vector_get', self::ofType($frames, 'tool-input-start')[0]['toolName']);
        self::assertSame('pending', $this->row($approvals[0]['data']['id'])['status'] ?? null);

        $this->decide($approvals[0]['data']['id'], 'reject')->assertOk();
        $order = $this->shop()->table('orders')->select('status')->where('id', '=', (int) DemoAiReplies::SHIPPED)->first();
        self::assertSame('paid', is_array($order) ? $order['status'] : null);
    }

    public function testAStaleExecutingRowIsAnsweredFailedWithTheUnknownOutcomeNotExecuting(): void
    {
        $approval = $this->proposed($this->update($this->stock + 5));
        $this->executing($approval['id'], ApprovalStore::EXECUTING_MINUTES + 1);

        $answer = $this->decide($approval['id'])->assertStatus(409)->json();
        self::assertIsArray($answer);
        self::assertSame('failed', $answer['status']);
        self::assertSame(['error' => Approval::UNKNOWN_OUTCOME], $answer['result']);
        self::assertSame('failed', $this->row($approval['id'])['status'] ?? null);
        $this->decide($approval['id'], 'reject')->assertStatus(409)->assertJsonPath('status', 'failed');

        // nothing ran: neither the first request, which "died", nor these
        self::assertSame($this->stock, $this->stockNow());
        self::assertSame([], $this->auditRows());
    }

    public function testAStaleExecutingRowIsFailedOnRejectToo(): void
    {
        $approval = $this->proposed($this->update($this->stock + 5));
        $this->executing($approval['id'], ApprovalStore::EXECUTING_MINUTES + 1);

        $this->decide($approval['id'], 'reject')->assertStatus(409)->assertJsonPath('status', 'failed')->assertJsonPath('result.error', Approval::UNKNOWN_OUTCOME);
    }

    public function testARecentExecutingRowIsLeftToTheRequestRunningIt(): void
    {
        $approval = $this->proposed($this->update($this->stock + 5));
        $this->executing($approval['id'], 1);

        $this->decide($approval['id'])->assertStatus(409)->assertJsonPath('status', 'executing');
        self::assertSame('executing', $this->row($approval['id'])['status'] ?? null);
        self::assertSame($this->stock, $this->stockNow());
    }

    public function testTheModelIsToldAnUnknownOutcomeAsUnknown(): void
    {
        $approval = $this->proposed($this->update($this->stock + 5));
        $this->executing($approval['id'], ApprovalStore::EXECUTING_MINUTES + 1);

        $this->script([Assistant::OUTCOME_PROMPT => 'Check the History.']);
        $this->turn(body: ['conversationId' => 'c_test', 'messages' => [['role' => 'approval', 'id' => $approval['id']]]]);

        $sent = self::sent($this->calls()[0]);
        self::assertStringContainsString("Approval {$approval['id']}, your proposed vector_update to products#{$this->product}: approved, but whether the change was made is unknown", $sent);
        self::assertStringContainsString(Approval::UNKNOWN_OUTCOME, $sent);
        self::assertSame('failed', $this->row($approval['id'])['status'] ?? null);
        self::assertSame($this->stock, $this->stockNow());
    }

    public function testAProposalPastItsExpiryCannotBeDecidedThoughTheSweepDidNotRun(): void
    {
        $approval = $this->proposed($this->update($this->stock + 5));
        $id = ApprovalId::parse($approval['id']) ?? self::fail('an approval id');
        $this->vectorQuery()->table(ApprovalStore::TABLE)->where('id', '=', $id)->update(['expires_at' => '2026-01-01 00:00:00.000000']);
        $store = $this->store();

        // the UPDATE itself refuses it, whatever the caller checked before
        self::assertFalse($store->decide($id, ApprovalStatus::Executing));
        self::assertFalse($store->decide($id, ApprovalStatus::Rejected));
        self::assertSame('pending', $this->row($approval['id'])['status'] ?? null);
        self::assertTrue($store->decide($id, ApprovalStatus::Expired));
        self::assertSame('expired', $this->row($approval['id'])['status'] ?? null);
        self::assertSame($this->stock, $this->stockNow());
    }

    public function testARowNamingAToolOutsideTheWritesIsFailedWithoutDispatch(): void
    {
        // a row no ProposingTool stores: a read tool, which dispatched would answer and be `executed`
        $approval = $this->store()->propose(self::ACTOR_PREFIX . '01', null, 'c_test', 'vector_get', ['resource' => 'products', 'id' => (string) $this->product], 15);

        $answer = $this->decide($approval->publicId())->assertOk()->json();
        self::assertIsArray($answer);
        self::assertSame('failed', $answer['status']);
        self::assertSame(['error' => ApprovalController::UNKNOWN_TOOL], $answer['result']);
        self::assertSame('failed', $this->row($approval->publicId())['status'] ?? null);
    }

    public function testAFailedApprovalsRefusalReachesTheModelOnlyThroughTheBoundary(): void
    {
        // users' email is `ai.send` false; a refusal quoting it, from a hook and from validation
        $secret = 'ada.kept@example.test';
        $store = $this->store();
        $arguments = ['resource' => 'users', 'id' => '1', 'values' => ['email' => $secret, 'name' => 'Ada']];
        $hooked = $store->propose(self::ACTOR_PREFIX . '01', null, 'c_test', 'vector_update', $arguments, 15);
        $invalid = $store->propose(self::ACTOR_PREFIX . '01', null, 'c_test', 'vector_update', $arguments, 15);

        foreach ([$hooked, $invalid] as $approval) {
            self::assertTrue($store->decide($approval->id, ApprovalStatus::Executing));
        }

        $store->settle($hooked->id, ApprovalStatus::Failed, ['error' => "The address {$secret} is on the blocklist."]);
        $store->settle($invalid->id, ApprovalStatus::Failed, ['error' => json_encode(['errors' => [
            ['path' => ['email'], 'code' => 'unique', 'message' => "{$secret} is already taken."],
            ['path' => ['name'], 'code' => 'too_long', 'message' => "must be shorter than {$secret}."],
        ]], JSON_THROW_ON_ERROR)]);

        $this->script([Assistant::OUTCOME_PROMPT => 'It failed.']);
        $turn = $this->turn(body: ['conversationId' => 'c_test', 'messages' => [
            ['role' => 'user', 'content' => self::QUESTION],
            ['role' => 'assistant', 'content' => 'I proposed two changes.'],
            ['role' => 'approval', 'id' => $hooked->publicId()],
            ['role' => 'assistant', 'content' => 'The first failed.'],
            ['role' => 'approval', 'id' => $invalid->publicId()],
        ]]);

        self::assertSame(200, $turn['status']);
        $sent = self::everything($this->calls()[0]);
        self::assertStringNotContainsString($secret, $sent);
        self::assertStringNotContainsString('"email"', $sent, 'nor the name of the field kept back');
        self::assertStringContainsString(ModelBoundary::REFUSED, $sent);
        self::assertStringContainsString(ModelBoundary::INVALID, $sent);
        self::assertStringContainsString('"fields":[{"field":"name","code":"too_long"}],"otherFields":1', $sent);
    }

    public function testTheConversationAlternatesRolesAroundAnOutcome(): void
    {
        $approval = $this->proposed($this->update($this->stock + 5));
        $this->decide($approval['id'], 'reject')->assertOk();

        // an answer with no text dropped, or an edited conversation: user, then the outcome
        $this->script([Assistant::OUTCOME_PROMPT => 'Rejected; nothing changed.']);
        $turn = $this->turn(body: ['conversationId' => 'c_test', 'messages' => [
            ['role' => 'user', 'content' => self::QUESTION],
            ['role' => 'approval', 'id' => $approval['id']],
        ]]);
        self::assertSame(200, $turn['status']);
        self::assertSame(['user', 'assistant', 'user'], self::roles($this->calls()[0]));
        self::assertStringContainsString(Assistant::BRIDGE, self::sent($this->calls()[0]));

        // two of the admin's own messages in a row
        $this->script(['And which are out of stock?' => 'None.']);
        $this->turn(body: ['conversationId' => 'c_test', 'messages' => [
            ['role' => 'user', 'content' => self::QUESTION],
            ['role' => 'user', 'content' => 'And which are out of stock?'],
        ]]);
        $calls = $this->calls();
        self::assertSame(['user', 'assistant', 'user'], self::roles($calls[count($calls) - 1]));
    }

    public function testAConversationTrimmedToAnAssistantTurnStillOpensWithTheUser(): void
    {
        // the browser trims from the start: what is left can open with the assistant's answers
        $this->script(['And which are out of stock?' => 'None.']);
        $turn = $this->turn(body: ['conversationId' => 'c_test', 'messages' => [
            ['role' => 'assistant', 'content' => 'Lamps and chairs.'],
            ['role' => 'assistant', 'content' => 'Anything else?'],
            ['role' => 'user', 'content' => 'And which are out of stock?'],
        ]]);

        self::assertSame(200, $turn['status']);
        self::assertSame('None.', self::text($turn['frames']));
        self::assertSame(['user'], self::roles($this->calls()[0]));
        self::assertStringNotContainsString('Lamps and chairs.', self::sent($this->calls()[0]));
    }

    /** Puts approval $publicId in `executing`, approved $minutes ago, as a request that died mid-execution leaves it. */
    private function executing(string $publicId, int $minutes): void
    {
        $id = ApprovalId::parse($publicId) ?? self::fail('an approval id');
        $at = new \DateTimeImmutable("-{$minutes} minutes", new \DateTimeZone('UTC'));
        $this->vectorQuery()->table(ApprovalStore::TABLE)->where('id', '=', $id)->update(['status' => 'executing', 'decided_at' => $at->format('Y-m-d H:i:s.u')]);
    }

    private function store(): ApprovalStore
    {
        $database = $this->app->get(VectorDatabase::class);
        self::assertInstanceOf(VectorDatabase::class, $database);

        return new ApprovalStore($database);
    }

    /** @return list<string> the roles of the messages a recorded call carried, in order */
    private static function roles(\NeuronAI\Testing\RequestRecord $call): array
    {
        return array_values(array_map(static fn (\NeuronAI\Chat\Messages\Message $m): string => $m->getRole(), $call->messages));
    }

    /** @return list<string> the error texts of the tool results a recorded call carried */
    private static function toolErrors(\NeuronAI\Testing\RequestRecord $call): array
    {
        $errors = [];

        foreach ($call->messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $tool) {
                    $result = $tool->getResult();

                    if ($result instanceof \NeuronAI\Tools\ToolOutput && $result->isError()) {
                        $errors[] = $result->getText();
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * The value with the keys of every object sorted and lists left in order:
     * MySQL JSON columns reorder object keys, Postgres keeps insertion order.
     */
    private static function keySorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $value = array_map(self::keySorted(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
