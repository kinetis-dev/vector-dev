<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Http;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\JsonResponse;
use Kinetis\Neuron\McpTools;
use Kinetis\Vector\Auth\ActorContext;
use Kinetis\VectorAi\Approvals\Approval;
use Kinetis\VectorAi\Approvals\ApprovalStatus;
use Kinetis\VectorAi\Approvals\ApprovalStore;
use Kinetis\VectorAi\Assistant\ProposingTool;
use Kinetis\VectorAi\Auth\AiAvailability;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Usage\UsageLedger;
use NeuronAI\Tools\ToolOutput;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * The admin's decision on a change the assistant proposed:
 * `POST /vector/ai/approvals/{id}/approve` and `…/reject`, `{id}` the
 * `apr_…` id the stream announced. The body is empty, and any body is
 * ignored: what an approval executes is the row's tool and arguments,
 * never anything the request carries.
 *
 * Asked in this order:
 *
 * 1. 403 unless the assistant and its writes (`assistantEnabled`,
 *    `assistantWrites`) are on and AiAccessInterface lets this user use
 *    both (Feature::Assistant, Feature::AssistantWrites); a switch turned
 *    off stops what was proposed before it, too.
 * 2. 404 unless the row is this user's and was proposed under the same
 *    impersonator as now, null-safe: another user's proposal, and one an
 *    impersonation that has since ended (or begun) proposed, are as
 *    unknown as one that does not exist.
 * 3. 410 when it was still pending past its expiry, which marks it
 *    `expired`, or had expired already. The UPDATE that decides it asks
 *    the expiry too, so one that expires after this check is 410 as well.
 * 4. 409 when it is no longer pending (one conditional UPDATE moves it
 *    from `pending`, so of two requests only one wins) with its
 *    `status`, and its `result` when `executed` or `failed`: a retried
 *    approval reads what the first did, and nothing runs twice. A row
 *    still `executing` ApprovalStore::EXECUTING_MINUTES after it was
 *    approved is first failed with Approval::UNKNOWN_OUTCOME (its request
 *    did not finish) so the answer is that definite `failed`, never a
 *    second execution.
 *
 * An approval then registers ActorContext(user, impersonator, origin:
 * `assistant`) on the request scope before anything that writes is
 * resolved, and calls the tool through McpTools, VectorTools under this
 * user, with Vector's policy, validation, version check, hooks and audit,
 * the audit rows marked `origin = 'assistant'`. The row keeps the
 * outcome: `executed` with the tool's result, or `failed` with the tool's
 * own refusal, or with Approval::MAY_HAVE_CHANGED when it threw; the
 * write may have committed before the exception. A row naming a tool
 * outside ProposingTool::TOOLS is `failed` without dispatching anything. Answers
 * `{status, result}`. One log line and one usage row per approval: who,
 * the approval id, the tool and the outcome, never its arguments or
 * result.
 */
#[RoutePrefix('/vector/ai')]
#[Middleware('@vector')]
final readonly class ApprovalController
{
    /** The origin an approved change is recorded with. */
    public const string ORIGIN = 'assistant';

    /** What a failure that threw is stored and answered as: never the exception's text, and the change may have been made. */
    public const string FAILED = Approval::MAY_HAVE_CHANGED;

    /** What a row naming a tool the assistant may not propose is failed with; nothing is dispatched. */
    public const string UNKNOWN_TOOL = 'This proposal names a tool the assistant may not propose; nothing was changed.';

    /**
     * How deep a tool's result is decoded: well past ApprovalStore::MAX_DEPTH,
     * so a result deeper than the store keeps reaches settle() and is stored as
     * TOO_DEEP; only one deeper than this is mapped to TOO_DEEP here.
     */
    private const int DECODE_DEPTH = 512;

    public function __construct(
        private CurrentUserInterface $user,
        private AiAvailability $availability,
        private ApprovalStore $approvals,
        private UsageLedger $usage,
        private RequestScope $scope,
        private LoggerInterface $logger,
    ) {}

    #[Post('/approvals/{id}/approve')]
    public function approve(string $id): ResponseInterface
    {
        $this->available();
        [$approval, $impersonatorId] = $this->owned($id);
        $this->decidable($approval);

        if (!$this->approvals->decide($approval->id, ApprovalStatus::Executing)) {
            return $this->conflict($approval);
        }

        $started = hrtime(true);
        // before anything that writes is resolved: every row Vector writes for this call says where it came from
        $this->scope->instance(ActorContext::class, new ActorContext($this->user, $impersonatorId, self::ORIGIN));
        [$status, $result, $category] = $this->execute($approval);
        // as stored: a retried approval, and the conversation, read what this response says
        $result = $this->approvals->settle($approval->id, $status, $result);

        $durationMs = intdiv(hrtime(true) - $started, 1_000_000);
        $this->usage->record((string) $this->user->id(), $impersonatorId, Feature::AssistantWrites, '', '', 0, 0, $durationMs, 1, 'approval:' . $status->value);
        $this->logger->info('An assistant proposal was approved.', [
            'actor' => (string) $this->user->id(),
            'impersonator' => $impersonatorId,
            'feature' => Feature::AssistantWrites->value,
            'approval' => $approval->publicId(),
            'tool' => $approval->tool,
            'outcome' => $status->value,
            'category' => $category,
            'durationMs' => $durationMs,
        ]);

        return JsonResponse::create(['status' => $status->value, 'result' => $result], 200, ['Cache-Control' => 'no-store']);
    }

    #[Post('/approvals/{id}/reject')]
    public function reject(string $id): ResponseInterface
    {
        $this->available();
        [$approval, $impersonatorId] = $this->owned($id);
        $this->decidable($approval);

        if (!$this->approvals->decide($approval->id, ApprovalStatus::Rejected)) {
            return $this->conflict($approval);
        }

        $this->logger->info('An assistant proposal was rejected.', [
            'actor' => (string) $this->user->id(),
            'impersonator' => $impersonatorId,
            'feature' => Feature::AssistantWrites->value,
            'approval' => $approval->publicId(),
            'tool' => $approval->tool,
            'outcome' => ApprovalStatus::Rejected->value,
        ]);

        return JsonResponse::create(['status' => ApprovalStatus::Rejected->value, 'result' => null], 200, ['Cache-Control' => 'no-store']);
    }

    /** 403 unless the assistant may write for this user, now: AiAvailability::permits(), as capabilities.ai asks it. */
    private function available(): void
    {
        if (!$this->availability->permits(Feature::AssistantWrites)) {
            throw new AiRequestException('The assistant may not make changes for this account.', 403);
        }
    }

    /**
     * The approval when it is this user's, under the impersonator of now; else 404.
     *
     * @return array{Approval, ?string} the approval and the impersonator of now
     */
    private function owned(string $id): array
    {
        // the impersonator of the identity the request was built for, before any origin is registered
        $actor = $this->scope->get(ActorContext::class);
        $impersonatorId = $actor instanceof ActorContext ? $actor->impersonatorId : null;

        $approval = $this->approvals->owned($id, (string) $this->user->id(), $impersonatorId)
            ?? throw new AiRequestException('There is no such proposal.', 404);

        return [$approval, $impersonatorId];
    }

    /**
     * 410 for a proposal past its expiry, marking it expired when it was
     * still pending. One still executing past EXECUTING_MINUTES is failed
     * with the unknown outcome (one conditional UPDATE, nothing run again),
     * so the 409 that follows answers `failed`. One another request decided
     * in the meantime is left to the conditional UPDATE that follows, which
     * answers 409.
     */
    private function decidable(Approval $approval): void
    {
        if ($approval->status === ApprovalStatus::Executing) {
            $this->approvals->failStale(ApprovalStore::staleBefore(), $approval->id);

            return;
        }

        $expired = $approval->status === ApprovalStatus::Expired
            || ($approval->status === ApprovalStatus::Pending && $approval->expired(ApprovalStore::now()));

        if (!$expired) {
            return;
        }

        if ($approval->status === ApprovalStatus::Expired || $this->approvals->decide($approval->id, ApprovalStatus::Expired)) {
            throw new AiRequestException('This proposal expired before it was decided.', 410);
        }
    }

    /**
     * 409: the row as it stands now, with its result when it was executed
     * or failed, or 410 when it is still pending because it expired
     * between decidable() and the UPDATE, which refused it for that.
     */
    private function conflict(Approval $approval): ResponseInterface
    {
        $now = $this->approvals->fresh($approval->id) ?? $approval;

        if ($now->status === ApprovalStatus::Pending) {
            $this->decidable($now);
        }

        $body = ['error' => 'This proposal was already decided.', 'status' => $now->status->value];

        if ($now->status === ApprovalStatus::Executed || $now->status === ApprovalStatus::Failed) {
            $body['result'] = $now->result;
        }

        return JsonResponse::create($body, 409, ['Cache-Control' => 'no-store']);
    }

    /**
     * Calls the row's tool with the row's arguments, through McpTools,
     * resolved now, after the ActorContext above was registered.
     *
     * @return array{ApprovalStatus, mixed, ?string} the outcome, what the row keeps of it, and a failure's category
     */
    private function execute(Approval $approval): array
    {
        // only a write the assistant may propose: anything else in the row is refused, never dispatched
        if (!in_array($approval->tool, ProposingTool::TOOLS, true)) {
            return [ApprovalStatus::Failed, ['error' => self::UNKNOWN_TOOL], 'refused'];
        }

        try {
            $tools = $this->scope->get(McpTools::class);

            if (!$tools instanceof McpTools) {
                throw new \LogicException('McpTools is not available.');
            }

            [$tool] = $tools->select($approval->tool);
            $tool->setInputs($approval->arguments);
            $tool->execute();
            $result = $tool->getResult();
        } catch (\Throwable $e) {
            // never the exception's text: it can quote data, and the row and the answer are kept;
            // and never "nothing changed": the write may have committed before it threw
            $this->logger->error('An approved assistant proposal failed.', [
                'approval' => $approval->publicId(),
                'tool' => $approval->tool,
                'exception_class' => $e::class,
                'exception_at' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return [ApprovalStatus::Failed, ['error' => self::FAILED], 'error'];
        }

        if ($result instanceof ToolOutput) {
            // the tool's own refusal (Vector's policy, validation or a stale version) as an MCP client would read it
            return [ApprovalStatus::Failed, ['error' => $result->isError() ? $result->getText() : self::FAILED], 'refused'];
        }

        try {
            // past MAX_DEPTH on purpose: settle() stores a result deeper than that as TOO_DEEP
            $decoded = json_decode($result, true, self::DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $decoded = $e->getCode() === JSON_ERROR_DEPTH ? ApprovalStore::TOO_DEEP : null;
        }

        return [ApprovalStatus::Executed, $decoded, null];
    }
}
