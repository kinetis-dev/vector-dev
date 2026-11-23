<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Actions;

use Kinetis\Vector\Actions\ActionRequest;
use Kinetis\Vector\Actions\ActionResponse;
use Kinetis\Vector\Actions\VectorAction;
use Kinetis\Vector\Fields\Field;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Store\JobStore;
use Kinetis\VectorAi\Auth\AiAvailability;
use Kinetis\VectorAi\Failure\AiFailure;
use Kinetis\VectorAi\Failure\AiFailureMapper;
use Kinetis\VectorAi\Http\AiRequestException;
use Kinetis\VectorAi\Providers\ResolvedProvider;
use Kinetis\VectorAi\Writing\WritingAssistant;
use Kinetis\VectorAi\Writing\WritingGuard;
use Kinetis\VectorAi\Writing\WritingOperation;

/**
 * Writing help across many records, as proposals the admin reviews
 * (docs/ai.md, "Bulk rewrite proposals"): a queued action that sends one
 * field of each selected record to AI, one single-shot writing call per
 * record, its text delimited and labelled as content (WritingAssistant),
 * so no record steers another, and leaves its job one CSV,
 * `<key field>,<field>`, of the proposed texts. It writes nothing to a
 * record. A resource opts in by listing a subclass, which names field()
 * and operation(), in its `actions()`.
 *
 * WritingGuard's rules are asked again at the start of every chunk, with
 * the `ai` settings read anew (Vector runs each chunk on a request scope
 * of its own). A refusal before any record has a row fails the whole job
 * before any provider call; after one, the rest are not sent
 * (`unavailable`) and finish() still delivers what was made. Each chunk
 * declares the records it proposed for (ActionResponse::withRecords()):
 * only those are audited, and counted as affected.
 *
 * The CSV finish() returns is never larger than JobStore::MAX_FILE_BYTES,
 * which the job's file must fit: rows are added in the order the
 * proposals were made until the next would pass it, and a proposal that
 * did not fit is left out of the file and counted in the job's message
 * (its row stays until the prune, as every row does).
 *
 * A chunk reads what it needs of the store and the ledger once, at its
 * start (which of its records are done, today's budget left, the CSV's
 * bytes so far) and counts on in memory as it goes: each call's tokens
 * off the budget, each record's outcome into done. Another request's
 * spending in the meantime shows at the next chunk.
 *
 * Chunks are delivered at least once: every record's outcome is a row of
 * RewriteStore keyed by (job, record), asked before the call, so a chunk
 * run again calls no provider for a record it already did and the CSV,
 * built from those rows in finish(), holds each record once. finish()
 * deletes nothing: `vector-ai:approvals:prune` deletes the rows of every
 * ended job, done included. A record whose key or proposal ends in a
 * backslash is left out and counted: the CSV could not carry it back.
 */
abstract class ProposeRewrites extends VectorAction
{
    /**
     * How many bytes of CSV rows a run makes at most before it stops
     * calling AI, counted as rowBytes() counts them: JobStore::MAX_FILE_BYTES,
     * which the CSV must fit, less room for the answer that passes it.
     */
    public const int FILE_LIMIT = JobStore::MAX_FILE_BYTES - 4 * 1024 * 1024;

    public function __construct(
        private readonly WritingGuard $guard,
        private readonly WritingAssistant $writing,
        private readonly RewriteStore $store,
        private readonly AiFailureMapper $failures,
        private readonly AiAvailability $availability,
    ) {}

    /** The field whose text is rewritten: a string, text, Markdown or rich-text field writing help is on for (WritingGuard::helps()). */
    abstract public function field(): string;

    /** What is done to each text. */
    abstract public function operation(): WritingOperation;

    /** The language tag a translation targets (`de`, `pt-BR`); required for WritingOperation::Translate. */
    public function targetLocale(): ?string
    {
        return null;
    }

    /** Guidance on style for every record, at most WritingAssistant::MAX_INSTRUCTIONS characters; sent in the user turn, never the system turn. */
    public function instructions(): ?string
    {
        return null;
    }

    /**
     * How many bytes of CSV rows (rowBytes()) this run makes before it
     * stops calling AI; never more than FILE_LIMIT, whatever a subclass
     * returns. Checked before each call, so a run can pass it by one
     * answer.
     */
    protected function fileLimit(): int
    {
        return self::FILE_LIMIT;
    }

    /**
     * The most bytes the CSV finish() returns may hold; never more than
     * JobStore::MAX_FILE_BYTES, whatever a subclass returns.
     */
    protected function fileCap(): int
    {
        return JobStore::MAX_FILE_BYTES;
    }

    #[\Override]
    public function label(): string
    {
        return 'Propose AI rewrites of ' . Field::labelFrom($this->field());
    }

    #[\Override]
    public function icon(): ?string
    {
        return 'mdi-creation-outline';
    }

    #[\Override]
    public function confirmation(): ?string
    {
        return 'Each selected record\'s ' . Field::labelFrom($this->field()) . ' is sent to AI. Nothing is changed: you get a CSV of proposals to review, and import it in update mode to apply them.';
    }

    #[\Override]
    public function queued(): bool
    {
        return true;
    }

    #[\Override]
    public function showOnDetail(): bool
    {
        return false;
    }

    #[\Override]
    public function handle(ActionRequest $request): ActionResponse
    {
        if ($request->jobId === null) {
            return ActionResponse::danger('Rewrite proposals run only as a background job.');
        }

        try {
            [$visible, $field, $provider, $instructions] = $this->configured($request->resource);
        } catch (AiRequestException $e) {
            return $this->unavailable($request, $request->jobId, $e);
        }

        $name = $this->field();
        $key = $visible->primaryKeyField();
        $operation = $this->operation();
        $format = ($field['type'] ?? null) === 'markdown' ? 'markdown' : 'plain';
        $proposed = [];
        // the proposals' CSV bytes so far, read once and counted on as this chunk proposes
        $bytes = $this->store->bytes($request->jobId);
        $limit = min($this->fileLimit(), self::FILE_LIMIT);
        // the records done in an earlier delivery of this chunk, read once; this chunk's own are added as it goes
        $done = $this->store->doneAmong($request->jobId, self::keys($request));
        // today's budget left, read once; every call this chunk makes is counted off it
        $budget = $this->availability->remaining();

        foreach ($request->records as $record) {
            $id = self::recordKey($record, $key);

            // done in an earlier delivery of this chunk, or earlier in this one: never sent twice
            if ($id === null || isset($done[$id])) {
                continue;
            }

            // whatever follows records a row for it
            $done[$id] = true;

            $text = $record[$name] ?? null;
            $left = $this->leftOut($visible, $record, $id, $text, $bytes >= $limit, $budget);

            if ($left !== null) {
                $this->store->leaveOut($request->jobId, $id, $left[0], $left[1]);

                continue;
            }

            /** @var string $text */
            $tokens = 0;

            try {
                $answer = $this->writing->write($provider, $field, $operation, $text, $format, $this->targetLocale(), $instructions, $tokens);
            } catch (\Throwable $e) {
                $this->leaveOutFailed($request->jobId, $id, $e);

                continue;
            } finally {
                // what the ledger recorded for the call, however it ended
                $budget -= $tokens;
            }

            if (!$this->proposed($request->jobId, $id, $answer)) {
                continue;
            }

            $bytes += self::rowBytes($id, $answer['text']);
            $proposed[] = $id;
        }

        // only the records proposed for are audited; one done in an earlier delivery is not declared again
        return ActionResponse::message('Proposed ' . count($proposed) . ' rewrite(s) in this chunk.')->withRecords($proposed);
    }

    /**
     * The answer to a chunk the guard refused: the first chunk fails the
     * job; a later one keeps what earlier chunks made, for finish() to
     * deliver, and records the rest of its records as not sent.
     */
    private function unavailable(ActionRequest $request, int $jobId, AiRequestException $e): ActionResponse
    {
        if (!$this->store->started($jobId)) {
            return ActionResponse::danger($e->getMessage());
        }

        $unsent = $this->leaveOutRest($request, 'unavailable');

        return ActionResponse::message("Writing help is no longer available to this run: {$unsent} record(s) in this chunk were not sent.")->withRecords([]);
    }

    /**
     * A record's key as text, or null when it has none.
     *
     * @param array<string, mixed> $record
     */
    private static function recordKey(array $record, string $key): ?string
    {
        $id = $record[$key] ?? null;

        return is_int($id) || is_string($id) ? (string) $id : null;
    }

    /**
     * Why record $id is not sent, as a RewriteStore status and reason, in
     * the order they are asked; null when it is sent.
     *
     * @param array<string, mixed> $record
     * @return array{string, string}|null
     */
    private function leftOut(ResourceDescriptor $visible, array $record, string $id, mixed $text, bool $fileFull, int $budget): ?array
    {
        return match (true) {
            !self::encodable($id) => [RewriteStore::SKIPPED, 'unencodable'],
            !$this->guard->mayUpdate($visible, $record) => [RewriteStore::SKIPPED, 'forbidden'],
            !is_string($text) || trim($text) === '' => [RewriteStore::SKIPPED, 'empty'],
            mb_strlen($text) > WritingAssistant::MAX_TEXT => [RewriteStore::SKIPPED, 'too_long'],
            $fileFull => [RewriteStore::BUDGET, 'file_full'],
            $budget <= 0 => [RewriteStore::BUDGET, 'budget'],
            default => null,
        };
    }

    /** Records record $id's call as failed, by its category only: the message can quote the provider's answer. */
    private function leaveOutFailed(int $jobId, string $id, \Throwable $e): void
    {
        $failure = $e instanceof AiFailure ? $e : $this->failures->map($e);
        $this->store->leaveOut($jobId, $id, RewriteStore::FAILED, $failure->category->value);
    }

    /**
     * Records $answer as record $id's proposal; false, with the reason it
     * is left out recorded instead, for an empty answer or one the CSV
     * could not carry.
     *
     * @param array{text: string, operation: string, model: string, truncated: bool} $answer
     */
    private function proposed(int $jobId, string $id, array $answer): bool
    {
        if (trim($answer['text']) === '') {
            $this->store->leaveOut($jobId, $id, RewriteStore::FAILED, 'empty_answer');

            return false;
        }

        // the CSV could not carry it: the importer would read its last backslash as escaping the closing quote
        if (!self::encodable($answer['text'])) {
            $this->store->leaveOut($jobId, $id, RewriteStore::SKIPPED, 'unencodable');

            return false;
        }

        $this->store->propose($jobId, $id, $answer['text'], $answer['truncated']);

        return true;
    }

    /**
     * The CSV of the run's proposals, `<key field>,<field>`, one row per
     * record in the order they were made, quoted as Vector's importer
     * parses it and otherwise exactly the proposed text; with a message
     * counting what was left out. The run's rows are kept: Vector saves
     * the file on the job after this returns, and the rows go only once
     * the job is done with its file (RewriteStore::deleteEnded()), so a
     * save that fails loses nothing.
     */
    #[\Override]
    public function finish(ActionRequest $request): ?ActionResponse
    {
        if ($request->jobId === null) {
            return null;
        }

        $resource = $request->resource;
        $field = $this->field();
        $proposals = $this->store->proposals($request->jobId);
        [$csv, $fitted] = self::csvWithin(
            [$resource->primaryKeyField(), $field],
            array_map(static fn (array $p): array => [$p['key'], $p['text']], $proposals),
            min($this->fileCap(), JobStore::MAX_FILE_BYTES),
        );
        $cut = count(array_filter(array_slice($proposals, 0, $fitted), static fn (array $p): bool => $p['truncated']));
        $message = self::summary($this->store->tally($request->jobId), $cut, count($proposals) - $fitted, Field::labelFrom($field), $resource->primaryKeyField());

        return ActionResponse::download($csv, "{$resource->slug()}-{$field}-proposals-{$request->jobId}.csv", 'text/csv; charset=utf-8', $message);
    }

    /**
     * A CSV document: a UTF-8 byte-order mark, then each row through
     * fputcsv with the delimiter, enclosure and escape character
     * Kinetis\Vector\Import\CsvReader reads with, lines ending CRLF. No
     * formula guard: the importer would keep its apostrophe in the value.
     * A value ending in a backslash cannot be written so that importer
     * reads it back (encodable()) and is refused rather than written.
     *
     * @param list<string> $header
     * @param list<list<string>> $rows
     * @throws \InvalidArgumentException for a value encodable() refuses
     */
    public static function csv(array $header, array $rows): string
    {
        return self::csvWithin($header, $rows, PHP_INT_MAX)[0];
    }

    /**
     * csv(), at most $maxBytes long: the header and then rows in order,
     * stopping before the first row that would pass $maxBytes; the CSV and
     * how many of $rows it holds.
     *
     * @param list<string> $header
     * @param list<list<string>> $rows
     * @return array{string, int}
     * @throws \InvalidArgumentException for a value encodable() refuses
     * @throws \LengthException when not even the header fits
     */
    public static function csvWithin(array $header, array $rows, int $maxBytes): array
    {
        foreach ([$header, ...$rows] as $row) {
            foreach ($row as $value) {
                if (!self::encodable($value)) {
                    throw new \InvalidArgumentException('A CSV value ending in a backslash cannot be read back by Vector\'s importer.');
                }
            }
        }

        $out = fopen('php://memory', 'r+');

        if ($out === false) {
            throw new CsvStreamException('Could not open a memory stream.');
        }

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ',', '"', '\\', "\r\n");

        if ((int) ftell($out) > $maxBytes) {
            fclose($out);

            throw new \LengthException("A CSV header does not fit in {$maxBytes} bytes.");
        }

        $fitted = 0;

        foreach ($rows as $row) {
            $before = max(0, (int) ftell($out));
            fputcsv($out, $row, ',', '"', '\\', "\r\n");

            if ((int) ftell($out) > $maxBytes) {
                ftruncate($out, $before);

                break;
            }

            $fitted++;
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return [$csv, $fitted];
    }

    /**
     * What a proposal's row adds to the CSV, at most: its key and text
     * with every quote doubled, both quoted, the comma and the CRLF.
     * RewriteStore::bytes() sums the same over the stored rows.
     */
    public static function rowBytes(string $key, string $text): int
    {
        return strlen($key) + substr_count($key, '"') + strlen($text) + substr_count($text, '"') + RewriteStore::ROW_FRAMING;
    }

    /**
     * Whether $value survives the CSV round trip: fputcsv writes a value
     * ending in `\` as `…\"`, and CsvReader, reading with `\` as its escape
     * character, takes that as an escaped quote and runs on into the next
     * rows. Any other backslash is read back as written.
     */
    public static function encodable(string $value): bool
    {
        return !str_ends_with($value, '\\');
    }

    /**
     * The field as the run is configured, its provider and its guidance,
     * refused, before any provider call, when writing help may not run on
     * it for this user.
     *
     * @return array{ResourceDescriptor, array<string, mixed>, ResolvedProvider, ?string}
     * @throws AiRequestException
     */
    private function configured(ResourceDescriptor $resource): array
    {
        [$visible, $field] = $this->guard->field($resource->slug(), $this->field());
        $this->guard->writable($visible, $field, 'update');

        // the records come as the detail view serializes them: a field it hides would read as empty on every one
        if (!in_array(Field::VIEW_SHOW, is_array($field['views'] ?? null) ? $field['views'] : [], true)) {
            throw new AiRequestException("This field of {$visible->label()} is not on its detail view, so its text cannot be read for proposals: show it there, or propose rewrites of another field.", 422);
        }

        $this->guard->sendable($visible, $this->field());

        if ($this->operation() === WritingOperation::Translate && !WritingOperation::isLocale($this->targetLocale() ?? '')) {
            throw new AiRequestException(static::class . ' translates without a language tag: return one, such as de or pt-BR, from targetLocale().', 422);
        }

        $instructions = $this->instructions();

        if ($instructions !== null && mb_strlen($instructions) > WritingAssistant::MAX_INSTRUCTIONS) {
            throw new AiRequestException(static::class . '::instructions() is longer than ' . WritingAssistant::MAX_INSTRUCTIONS . ' characters.', 422);
        }

        return [$visible, $field, $this->guard->provider(), $instructions === null ? null : WritingAssistant::guidance($instructions)];
    }

    /**
     * What the job says it did: the proposals, then each way a record was
     * left out, by count.
     *
     * @param array<string, array<string, int>> $tally status => reason => count
     * @param int $cut how many proposals were cut at the field's length cap
     * @param int $unfitted how many proposals were left out of the CSV, which held no more
     */
    private static function summary(array $tally, int $cut, int $unfitted, string $label, string $key): string
    {
        $proposed = array_sum($tally[RewriteStore::PROPOSED] ?? []) - $unfitted;
        $parts = ["Proposed rewrites of {$label} for {$proposed} record(s): review the CSV, then import it in update mode keyed by {$key} to apply them."];

        if ($cut > 0) {
            $parts[] = "{$cut} answer(s) were cut at the field's length limit.";
        }
        $reasons = [
            'forbidden' => 'this account may not update',
            'empty' => 'had no text',
            'too_long' => 'were longer than ' . WritingAssistant::MAX_TEXT . ' characters',
            'unencodable' => 'ended in a backslash, which the CSV cannot carry back to the import',
        ];

        foreach ($tally[RewriteStore::SKIPPED] ?? [] as $reason => $count) {
            $parts[] = "Left out {$count} that " . ($reasons[$reason] ?? $reason) . '.';
        }

        foreach ($tally[RewriteStore::FAILED] ?? [] as $category => $count) {
            $parts[] = $category === 'empty_answer' ? "{$count} AI answer(s) were empty." : "{$count} AI call(s) failed ({$category}).";
        }

        $stops = [
            'budget' => "today's budget ran out",
            'file_full' => "the proposals filled the job's file; run the rest as another selection",
            'unavailable' => 'writing help stopped being available to this run',
        ];

        foreach ($tally[RewriteStore::BUDGET] ?? [] as $reason => $count) {
            $parts[] = 'Stopped calling AI: ' . ($stops[$reason] ?? $reason) . ", so {$count} record(s) were not sent.";
        }

        if ($unfitted > 0) {
            $parts[] = "Left out {$unfitted} proposal(s) because the proposals filled the job's file; run the rest as another selection.";
        }

        return implode(' ', $parts);
    }

    /**
     * Records every record of the chunk not done yet as not sent (BUDGET,
     * $reason); how many.
     */
    private function leaveOutRest(ActionRequest $request, string $reason): int
    {
        if ($request->jobId === null) {
            return 0;
        }

        $done = $this->store->doneAmong($request->jobId, self::keys($request));
        $unsent = 0;

        foreach (self::keys($request) as $id) {
            if (isset($done[$id])) {
                continue;
            }

            $this->store->leaveOut($request->jobId, $id, RewriteStore::BUDGET, $reason);
            $done[$id] = true;
            $unsent++;
        }

        return $unsent;
    }

    /**
     * The keys of the chunk's records, in order, as text; a record without
     * one is left out.
     *
     * @return list<string>
     */
    private static function keys(ActionRequest $request): array
    {
        $key = $request->resource->primaryKeyField();
        $keys = [];

        foreach ($request->records as $record) {
            $id = $record[$key] ?? null;

            if (is_int($id) || is_string($id)) {
                $keys[] = (string) $id;
            }
        }

        return $keys;
    }
}
