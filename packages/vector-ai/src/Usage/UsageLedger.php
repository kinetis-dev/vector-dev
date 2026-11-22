<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Usage;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\VectorAi\Feature;

/**
 * `vector_ai_usage`: one row per AI call (who, impersonated by whom,
 * for which feature, on which provider and model, the tokens in and out,
 * how long, how many tool calls and how it ended) on Vector's own
 * connection. remaining() is what the daily budget leaves a person
 * today (UTC). Every number is the caller's, per call, never a counter
 * kept in the worker, Neuron's UsageMeter keeps one in a static and is
 * not used: the tokens a call's answer reported, or, for an assistant
 * call that ended without them, AssistantLedger's estimate, its outcome
 * marked `:estimated`. Built per request.
 */
final readonly class UsageLedger
{
    private const string TABLE = 'vector_ai_usage';

    public function __construct(private VectorDatabase $db) {}

    public function record(
        string $actorId,
        ?string $impersonatorId,
        Feature $feature,
        string $provider,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $durationMs,
        int $toolCalls,
        string $outcome,
    ): void {
        $this->query()->insert([
            'actor_id' => $actorId,
            'impersonator_id' => $impersonatorId,
            'feature' => $feature->value,
            'provider' => $provider,
            'model' => mb_substr($model, 0, 191),
            'input_tokens' => max(0, $inputTokens),
            'output_tokens' => max(0, $outputTokens),
            'duration_ms' => max(0, $durationMs),
            'tool_calls' => max(0, $toolCalls),
            'outcome' => mb_substr($outcome, 0, 40),
            'created_at' => self::format(new DateTimeImmutable('now', new DateTimeZone('UTC'))),
        ]);
    }

    /** The tokens $actorId spent today (UTC), input and output together. */
    public function spentToday(string $actorId): int
    {
        $since = self::format(new DateTimeImmutable('today', new DateTimeZone('UTC')));
        // one aggregate over today's rows: input and output together
        $row = $this->query()
            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens), 0) AS spent')
            ->where('actor_id', '=', $actorId)
            ->where('created_at', '>=', $since)
            ->first();

        return is_array($row) ? (int) $row['spent'] : 0;
    }

    /** What $budget leaves $actorId today, never below 0. */
    public function remaining(string $actorId, int $budget): int
    {
        return $budget <= 0 ? 0 : max(0, $budget - $this->spentToday($actorId));
    }

    private static function format(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s.u');
    }

    private function query(): Query
    {
        return $this->db->query()->table(self::TABLE);
    }
}
