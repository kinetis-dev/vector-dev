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
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\Workflow\Observability\WorkflowError;

/**
 * One usage row per provider call of an assistant run, written once, from
 * the agent's own inference events. Keeps the run's token totals for its
 * log line, and the exception it ended on (exception()). One per run,
 * subscribed to that run's agent; never shared.
 *
 * What a call is charged depends on whether the provider produced output,
 * not on whether an HTTP status is known:
 *
 * - a complete answer: the tokens it reported, `answered`;
 * - an answer the departure stopped (StoppableHttpClient::STOP_REASON):
 *   the input reported, or estimated when none was (Anthropic reports it
 *   up front, OpenAI only at the end that never comes) and the larger of
 *   the output reported and the estimate of what streamed back,
 *   `client_gone`, with `:estimated` when either side was estimated;
 * - a stream the departure stopped before any text (the DepartureSignal
 *   fired and Neuron raised a ProviderException): estimated,
 *   `client_gone:estimated`, and not handed to AiFailureMapper, which
 *   would log it as the provider's failure;
 * - a failure after the provider streamed anything: estimated,
 *   `failed:<category>:estimated`; one with nothing streamed (no
 *   connection, an HTTP refusal, a Bedrock refusal, a timeout before the
 *   first byte) costs nothing, `failed:<category>` at 0 and 0;
 * - a call still in flight when the run was abandoned (settle()): as the
 *   two rows above, by whether anything streamed.
 *
 * An estimate is ESTIMATE_BYTES bytes a token: of the instructions and the
 * conversation as sent, each call's newest message and the text streamed
 * back, not the tool definitions, which are sent too. The usage table has
 * no column for it, so its outcome is marked `:estimated`. Each side is
 * the larger of the reported and the estimated, never their sum. The
 * daily budget is an abuse limit, not a bill.
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

    /** The exception the run ended on, as its WorkflowError reported it (before the error frames it sends) or null. */
    private ?\Throwable $exception = null;

    /** Whether the run is over (settle()): events after it (a dropped run's own release) record and map nothing. */
    private bool $closed = false;

    /**
     * @param int $promptBytes the bytes of the run's instructions and conversation, as sent to its first call
     * @param DepartureSignal|null $signal the run's: whether its stream was stopped by the departure; none for a run nothing can stop
     */
    public function __construct(
        private readonly UsageLedger $usage,
        private readonly ActorContext $actor,
        private readonly AiFailureMapper $failures,
        private readonly ResolvedProvider $provider,
        int $promptBytes = 0,
        private readonly ?DepartureSignal $signal = null,
    ) {
        $this->contextBytes = max(0, $promptBytes);
    }

    public function handle(object $event): void
    {
        if ($this->closed) {
            return;
        }

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
     * Closes the run: the call still in flight, if any (the run abandoned
     * when the browser left, or failed outside the call's own events) is
     * recorded as $outcome, estimated when anything streamed back and at 0
     * otherwise; nothing after this records or maps anything.
     */
    public function settle(string $outcome): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->started !== null) {
            $this->charge($outcome);
        }

        $this->closed = true;
    }

    /**
     * Whether $e is the departure's doing: the DepartureSignal fired (the
     * application stopped the stream) and Neuron raised what a stopped
     * stream raises. Anything else is a failure, whoever is left to see it.
     */
    public function isDeparture(\Throwable $e): bool
    {
        return $this->signal?->fired() === true && $e instanceof ProviderException;
    }

    /** Whether the run's DepartureSignal fired: the application stopped its stream because the browser left. */
    public function signalFired(): bool
    {
        return $this->signal?->fired() === true;
    }

    /** The exception the run ended on, as its WorkflowError reported it, or null. */
    public function exception(): ?\Throwable
    {
        return $this->exception;
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
        // the call's newest message (a tool's result, an approval's outcome) on top of what came before
        $this->contextBytes += strlen((string) json_encode($event->message, JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function stopped(InferenceStop $event): void
    {
        $message = $event->response->message();
        $usage = $message->getUsage();
        $in = $usage->inputTokens ?? 0;
        $out = $usage->outputTokens ?? 0;
        $toolCalls = $message instanceof ToolCallMessage ? count($message->getToolCalls()) : 0;

        if (!$message instanceof AssistantMessage || $message->stopReason() !== StoppableHttpClient::STOP_REASON) {
            $this->record($in, $out, $toolCalls, 'answered');

            return;
        }

        // stopped by the departure: what was reported so far, and estimates for what was not; the larger, never the sum
        $estimatedIn = $in <= 0;
        $streamed = $this->estimatedOutput();
        $this->record(
            $estimatedIn ? $this->estimatedInput() : $in,
            max($out, $streamed),
            $toolCalls,
            'client_gone' . ($estimatedIn || $streamed > $out ? self::ESTIMATED : ''),
        );
    }

    /**
     * The run failed: remembered for told(), which may have stopped reading
     * it before the failure itself was thrown. While a provider call was in
     * flight, the call is recorded, as the departure's when the signal
     * fired on a stopped stream (never mapped: AiFailureMapper logs the
     * provider's failures), as the provider's failure otherwise.
     */
    private function failed(WorkflowError $event): void
    {
        $this->exception = $event->exception;

        if ($this->started === null) {
            return;
        }

        if ($this->isDeparture($event->exception)) {
            $this->record($this->estimatedInput(), $this->estimatedOutput(), 0, 'client_gone' . self::ESTIMATED);

            return;
        }

        $this->failure = $this->failures->map($event->exception);
        $this->charge('failed:' . $this->failure->category->value);
    }

    /** The call in flight, ended without an answer: estimated when the provider streamed anything back, at 0 when it produced nothing. */
    private function charge(string $outcome): void
    {
        if ($this->streamedBytes > 0) {
            $this->record($this->estimatedInput(), $this->estimatedOutput(), 0, $outcome . self::ESTIMATED);

            return;
        }

        $this->record(0, 0, 0, $outcome);
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
