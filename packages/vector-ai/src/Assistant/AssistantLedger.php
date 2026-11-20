<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Assistant;

use Kinetis\Vector\Auth\ActorContext;
use Kinetis\VectorAi\Failure\AiFailure;
use Kinetis\VectorAi\Failure\AiFailureMapper;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Providers\ResolvedProvider;
use Kinetis\VectorAi\Usage\UsageLedger;
use NeuronAI\Agent\Observability\InferenceStart;
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Workflow\Observability\WorkflowError;

/**
 * One usage row per provider call of an assistant run, from the agent's
 * own inference events: an answer is `answered`, with its tokens and the
 * tool calls it asked for; a call the run failed in is `failed:<category>`,
 * mapped — and logged by category and status only — by AiFailureMapper.
 * Keeps the run's token totals for its log line. One per run, subscribed
 * to that run's agent; never shared.
 */
final class AssistantLedger
{
    /** When the provider call in flight started (hrtime), or null between calls. */
    private ?int $started = null;

    private int $inputTokens = 0;

    private int $outputTokens = 0;

    /** The provider failure the run ended on, mapped, when it ended on one. */
    private ?AiFailure $failure = null;

    public function __construct(
        private readonly UsageLedger $usage,
        private readonly ActorContext $actor,
        private readonly AiFailureMapper $failures,
        private readonly ResolvedProvider $provider,
    ) {}

    public function handle(object $event): void
    {
        match (true) {
            $event instanceof InferenceStart => $this->started = hrtime(true),
            $event instanceof InferenceStop => $this->stopped($event),
            $event instanceof WorkflowError => $this->failed($event),
            default => null,
        };
    }

    public function inputTokens(): int
    {
        return $this->inputTokens;
    }

    public function outputTokens(): int
    {
        return $this->outputTokens;
    }

    public function failure(): ?AiFailure
    {
        return $this->failure;
    }

    private function stopped(InferenceStop $event): void
    {
        $message = $event->response->message();
        $usage = $message->getUsage();
        $in = $usage->inputTokens ?? 0;
        $out = $usage->outputTokens ?? 0;
        $this->inputTokens += $in;
        $this->outputTokens += $out;
        $this->record($in, $out, $message instanceof ToolCallMessage ? count($message->getToolCalls()) : 0, 'answered');
    }

    /** A run that failed while a provider call was in flight failed in that call; any other failure is no call's. */
    private function failed(WorkflowError $event): void
    {
        if ($this->started === null) {
            return;
        }

        $this->failure = $this->failures->map($event->exception);
        $this->record(0, 0, 0, 'failed:' . $this->failure->category->value);
    }

    private function record(int $in, int $out, int $toolCalls, string $outcome): void
    {
        $started = $this->started ?? hrtime(true);
        $this->started = null;
        $this->usage->record(
            $this->actor->actorId(),
            $this->actor->impersonatorId,
            Feature::Assistant,
            $this->provider->name,
            $this->provider->model,
            $in,
            $out,
            intdiv(hrtime(true) - $started, 1_000_000),
            $toolCalls,
            $outcome,
        );
    }
}
