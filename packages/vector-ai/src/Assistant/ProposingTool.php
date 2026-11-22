<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Assistant;

use Kinetis\Mcp\KinetisMcpApplication;
use Kinetis\Mcp\ToolDefinition;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Vector\Auth\ActorContext;
use Kinetis\VectorAi\Approvals\ApprovalStore;
use Kinetis\VectorAi\Approvals\ToolArguments;
use Kinetis\VectorAi\Governance\ModelBoundary;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;

/**
 * One of Vector's write tools (`vector_create`, `vector_update`,
 * `vector_delete`, `vector_run_action`) as the assistant may call it:
 * calling it proposes the change and changes nothing. The model sees the
 * MCP tool's own name, description and input schema.
 *
 * execute() never dispatches. In order: the run's ToolBudget is asked;
 * arguments nesting deeper than a stored proposal can be read back with
 * (ApprovalStore::MAX_DEPTH) are refused (TOO_DEEP); the arguments are checked as the MCP dispatcher checks them
 * (ToolArguments), so a malformed call gets the same `{"errors": [...]}`
 * feedback; a resource ModelBoundary keeps from the model is refused, and
 * so are values for a field it keeps back; a change Vector's own
 * permissions would refuse this user (ModelBoundary::permits(): `create`,
 * or the record's `update` or `delete`) is refused (NOT_PERMITTED) rather
 * than shown as a card the approval would only fail; then the proposal is stored in
 * `vector_ai_approvals` for this actor and impersonator, added to the
 * run's Proposals for the admin to see, and the model is told it waits
 * for the admin (PROPOSED). Only the approve endpoint executes it, with
 * the row's arguments. Built per run; Neuron runs a clone for each call,
 * and every clone shares the run's budget, store and proposals.
 */
final class ProposingTool extends Tool
{
    /** The tools that propose rather than write. */
    public const array TOOLS = ['vector_create', 'vector_update', 'vector_delete', 'vector_run_action'];

    /** What the model is told of a stored proposal; %s is its `apr_…` id. */
    public const string PROPOSED = 'Proposed. Approval id %s is awaiting the admin\'s decision; stop and tell the admin what you proposed.';

    /** The refusal of values for a field kept from the model; it does not name the field. */
    public const string FIELD_REFUSED = 'this change writes a field that is not available to the assistant; propose only the fields vector_describe lists';

    /** What a proposal too deeply nested to be stored and read back (ApprovalStore::storable()) is answered with. */
    public const string TOO_DEEP = 'these arguments nest too deeply to be stored for approval; propose the change with plain values';

    /** The refusal of a change this user may not make (ModelBoundary::permits()): nothing is proposed. */
    public const string NOT_PERMITTED = 'the admin may not make this change: their permissions do not allow it, or there is no such record they can see; do not propose it, tell them instead';

    public function __construct(
        private readonly ToolDefinition $definition,
        private readonly ModelBoundary $boundary,
        private readonly ToolBudget $budget,
        private readonly ApprovalStore $store,
        private readonly Proposals $proposals,
        private readonly ActorContext $actor,
        private readonly string $conversationId,
        private readonly int $approvalMinutes,
    ) {
        $this->name = $definition->name;
        $this->description = $definition->description;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getInputSchema(): array
    {
        return $this->definition->inputSchema;
    }

    /** The call, its outcome (proposed, or a tool error) recorded in the run's ToolBudget. */
    #[\Override]
    public function execute(): void
    {
        $call = $this->budget->made();
        $this->propose();
        GovernedTool::settle($this->budget, $call, $this->getResult());
    }

    private function propose(): void
    {
        $arguments = $this->inputs;

        if (!$this->budget->take($this->name)) {
            $this->setResult(ToolOutput::error(GovernedTool::EXHAUSTED));

            return;
        }

        // before anything else reads them: a proposal stored too deep could never be decided on
        if (!ApprovalStore::storable($arguments)) {
            $this->setResult(ToolOutput::error(self::TOO_DEEP));

            return;
        }

        try {
            ToolArguments::validate($this->definition, $arguments);
        } catch (ValidationException $e) {
            $this->setResult(ToolOutput::error(KinetisMcpApplication::validationFeedback($e)));

            return;
        }

        $visible = $this->boundary->sendable($arguments['resource'] ?? null);

        if ($visible === null) {
            // unknown, not this user's, or kept from the model: one answer for all three
            $this->setResult(ToolOutput::error(ModelBoundary::UNAVAILABLE));

            return;
        }

        if (in_array($this->name, ['vector_create', 'vector_update'], true) && is_array($arguments['values'] ?? null)) {
            foreach (array_keys($arguments['values']) as $field) {
                if (!$this->boundary->sends($visible, (string) $field)) {
                    $this->setResult(ToolOutput::error(self::FIELD_REFUSED));

                    return;
                }
            }
        }

        if (!$this->boundary->permits($visible, $this->name, $arguments)) {
            // a card the approval would only fail: refused now, and nothing is stored
            $this->setResult(ToolOutput::error(self::NOT_PERMITTED));

            return;
        }

        /** @var array<string, mixed> $arguments */
        $approval = $this->store->propose(
            $this->actor->actorId(),
            $this->actor->impersonatorId,
            $this->conversationId,
            $this->name,
            $arguments,
            $this->approvalMinutes,
        );
        $this->proposals->add($approval);

        $this->setResult(sprintf(self::PROPOSED, $approval->publicId()));
    }
}
