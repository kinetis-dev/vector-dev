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
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\Workflow\Observability\WorkflowError;

/**
 * One usage row per provider call of an assistant run, from the agent's
 * own inference events: an answer is `answered`, with its tokens and the
 * tool calls it asked for; a call the run failed in is `failed:<category>`,
 * mapped — and logged by category and status only — by AiFailureMapper.
 * Keeps the run's token totals for its log line. One per run, subscribed
 * to that run's agent; never shared.
 *
 * A call that ends without the provider reporting any tokens — a stream
 * stopped because the browser left (StoppableHttpClient, outcome
 * `client_gone`), a stream cut or failed without an HTTP status, or a
 * call still in flight when the run was abandoned (settle()) — would
 * cost the daily budget nothing, though the provider may well bill it.
 * It is recorded with an estimate instead, ESTIMATE_BYTES bytes a token
 * (the prompt and conversation as sent, the newest message of each call
 * and the text streamed back), its outcome marked `:estimated` — the
 * usage table has no column for it. A call refused with an HTTP status
 * generated nothing and stays at 0. Reported usage is never estimated
 * over or added to: each call is one row, recorded once.
 */
final class AssistantLedger
{
    /** The bytes counted as one token when no usage was reported: about 4 for English, more tokens than that for most other text. */
    public const int ESTIMATE_BYTES = 4;

    /** What marks a row whose tokens were estimated, after its outcome. */
    public const string ESTIMATED = ':estimated';

    /** When the provider call in flight started (hrtime), or null between calls. */
    private ?int $started = null;

    private int $inputTokens = 0;

    private int $outputTokens = 0;

    /** The provider failure the run ended on, mapped, when it ended on one. */
    private ?AiFailure $failure = null;

    /** The bytes of everything the next call is sent, as far as they are seen here: what an input estimate counts. */
    private int $contextBytes;

    /** The bytes the call in flight streamed back so far: what an output estimate counts. */
    private int $streamedBytes = 0;

    /** @param int $promptBytes the bytes of the run's instructions and conversation, as sent to its first call */
    public function __construct(
        private readonly UsageLedger $usage,
        private readonly ActorContext $actor,
        private readonly AiFailureMapper $failures,
        private readonly ResolvedProvider $provider,
        int $promptBytes = 0,
    ) {
        $this->contextBytes = max(0, $promptBytes);
    }

    public function handle(object $event): void
    {
        match (true) {
            $event instanceof InferenceStart => $this->start($event),
            $event instanceof InferenceStop => $this->stopped($event),
            $event instanceof WorkflowError => $this->failed($event),
            default => null,
        };
    }

    /** $text, streamed back by the call in flight: counted toward its output estimate, and the next call's input. */
    public function streamed(string $text): void
    {
        $this->streamedBytes += strlen($text);
    }

    /**
     * Records the call still in flight, if any, as an estimate: the run
     * ended — abandoned when the browser left, or failed outside the
     * provider call's own events — before the provider answered it.
     */
    public function settle(string $outcome = 'client_gone'): void
    {
        if ($this->started !== null) {
            $this->record($this->estimatedInput(), $this->estimatedOutput(), 0, $outcome . self::ESTIMATED);
        }
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

    private function start(InferenceStart $event): void
    {
        $this->started = hrtime(true);
        $this->streamedBytes = 0;
        // the call's newest message — a tool's result, an approval's outcome — on top of what came before
        $this->contextBytes += strlen((string) json_encode($event->message, JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function stopped(InferenceStop $event): void
    {
        $message = $event->response->message();
        $usage = $message->getUsage();
        $in = $usage->inputTokens ?? 0;
        $out = $usage->outputTokens ?? 0;
        $toolCalls = $message instanceof ToolCallMessage ? count($message->getToolCalls()) : 0;
        $outcome = $message instanceof AssistantMessage && $message->stopReason() === StoppableHttpClient::STOP_REASON ? 'client_gone' : 'answered';

        if ($in + $out === 0) {
            // nothing reported — a stopped stream's usage never arrives: one estimated row instead, never both
            $this->record($this->estimatedInput(), $this->estimatedOutput(), $toolCalls, $outcome . self::ESTIMATED);

            return;
        }

        $this->record($in, $out, $toolCalls, $outcome);
    }

    /**
     * A run that failed while a provider call was in flight failed in that
     * call; any other failure is no call's. Refused with an HTTP status,
     * the call generated nothing; otherwise — a stream cut or stopped, a
     * timeout — it is estimated.
     */
    private function failed(WorkflowError $event): void
    {
        if ($this->started === null) {
            return;
        }

        $this->failure = $this->failures->map($event->exception);
        $outcome = 'failed:' . $this->failure->category->value;

        if ($this->failure->providerStatus !== null) {
            $this->record(0, 0, 0, $outcome);

            return;
        }

        $this->record($this->estimatedInput(), $this->estimatedOutput(), 0, $outcome . self::ESTIMATED);
    }

    private function estimatedInput(): int
    {
        return (int) ceil($this->contextBytes / self::ESTIMATE_BYTES);
    }

    private function estimatedOutput(): int
    {
        return (int) ceil($this->streamedBytes / self::ESTIMATE_BYTES);
    }

    private function record(int $in, int $out, int $toolCalls, string $outcome): void
    {
        $started = $this->started ?? hrtime(true);
        $this->started = null;
        // what this call streamed back is sent again with the next one
        $this->contextBytes += $this->streamedBytes;
        $this->streamedBytes = 0;
        $this->inputTokens += max(0, $in);
        $this->outputTokens += max(0, $out);
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
