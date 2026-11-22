<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Writing;

use Kinetis\Vector\Auth\ActorContext;
use Kinetis\VectorAi\Failure\AiFailureMapper;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Providers\ResolvedProvider;
use Kinetis\VectorAi\Usage\UsageLedger;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\NeuronException;

/**
 * Writing help for one field's text: one chat call with the operation's
 * fixed system prompt (WritingOperation), and a user turn holding the
 * person's guidance, if any, then their text between OPEN and CLOSE,
 * labelled as content to transform. The answer is cleaned (control
 * characters but `\n` and `\t` stripped) and cut at the field's cap
 * (cap()). Nothing is written: the admin puts the text in the input, and
 * the person saves it as their own edit.
 *
 * Every call is one row of the usage ledger, feature `writing`, however
 * it ends. No prompt, text, answer or provider error body is logged.
 * Built per request.
 */
final readonly class WritingAssistant
{
    /** The line before the person's text in the user turn. */
    public const string OPEN = '<<<TEXT';

    /** The line after it. */
    public const string CLOSE = 'TEXT>>>';

    /** The longest text taken, in characters. */
    public const int MAX_TEXT = 20000;

    /** The longest guidance taken, in characters. */
    public const int MAX_INSTRUCTIONS = 300;

    /** The longest answer when the field names no maximum length. */
    public const int MAX_OUTPUT = 20000;

    /** How many times its input an answer may be when the field names no maximum length. */
    public const int GROWTH = 4;

    public function __construct(
        private UsageLedger $usage,
        private ActorContext $actor,
        private AiFailureMapper $failures,
    ) {}

    /**
     * The block of the user turn that holds $text: delimited, and
     * labelled as content. The fake provider keys its replies by it.
     */
    public static function content(string $text): string
    {
        return "The text to transform:\n" . self::OPEN . "\n" . $text . "\n" . self::CLOSE;
    }

    /** $instructions without control characters but tab and line feed, trimmed; null when nothing is left. */
    public static function guidance(string $instructions): ?string
    {
        $clean = trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $instructions));

        return $clean === '' ? null : $clean;
    }

    /**
     * The longest answer kept for $field: its maximum-length rule, or
     * GROWTH times the input's length, never more than MAX_OUTPUT.
     *
     * @param array<string, mixed> $field a compiled field
     */
    public static function cap(array $field, string $text): int
    {
        foreach (is_array($field['rules'] ?? null) ? $field['rules'] : [] as $rule) {
            if (is_array($rule) && is_string($rule['constraint'] ?? null) && str_ends_with($rule['constraint'], '\\MaxLength') && is_int($rule['args'][0] ?? null)) {
                return max(1, $rule['args'][0]);
            }
        }

        return max(1, min(self::MAX_OUTPUT, self::GROWTH * mb_strlen($text)));
    }

    /**
     * $answer fit to put in an input: valid UTF-8, line breaks as `\n`,
     * every control character but `\n` and `\t` gone, and cut at $cap
     * characters.
     *
     * @return array{string, bool} the text, and whether it was cut
     */
    public static function clean(string $answer, int $cap): array
    {
        $text = mb_scrub($answer, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // C0 but tab and line feed, DEL, and C1
        $text = (string) preg_replace('/[\x{00}-\x{08}\x{0B}-\x{1F}\x{7F}-\x{9F}]/u', '', $text);
        $text = trim($text);

        // a model that echoes the markers anyway: the text between them
        if (str_starts_with($text, self::OPEN) && str_ends_with($text, self::CLOSE)) {
            $text = trim(substr($text, strlen(self::OPEN), -strlen(self::CLOSE)));
        }

        if (mb_strlen($text) <= $cap) {
            return [$text, false];
        }

        return [mb_substr($text, 0, $cap), true];
    }

    /**
     * @param array<string, mixed> $field the compiled field the text is for
     * @param 'plain'|'markdown' $format
     * @param-out int $tokens the tokens the call spent, input and output, as the usage ledger records them, 0 when it failed
     * @return array{text: string, operation: string, model: string, truncated: bool}
     * @throws \Kinetis\VectorAi\Failure\AiFailure when the provider fails
     */
    public function write(ResolvedProvider $provider, array $field, WritingOperation $operation, string $text, string $format, ?string $targetLocale, ?string $instructions, ?int &$tokens = null): array
    {
        $tokens = 0;
        $blocks = [];

        if ($instructions !== null) {
            $blocks[] = new TextContent("The person's guidance on style:\n" . $instructions);
        }

        $blocks[] = new TextContent(self::content($text));
        $answer = $this->call($provider, $operation->systemPrompt($format, $targetLocale), new UserMessage($blocks), $tokens);
        [$written, $truncated] = self::clean($answer, self::cap($field, $text));

        return ['text' => $written, 'operation' => $operation->value, 'model' => $provider->model, 'truncated' => $truncated];
    }

    /**
     * One chat call, recorded in the usage ledger whatever its end; a provider's failure as its category.
     *
     * @param-out int $tokens what the ledger records it spent
     */
    private function call(ResolvedProvider $provider, string $system, UserMessage $message, ?int &$tokens): string
    {
        $started = hrtime(true);
        // what the ledger records if the call ends some other way than the two below
        $outcome = 'failed:error';
        $in = 0;
        $out = 0;

        try {
            $response = $provider->provider->systemPrompt($system)->chat($message);
            $usage = $response->message()->getUsage();
            $in = $usage->inputTokens ?? 0;
            $out = $usage->outputTokens ?? 0;
            $outcome = 'answered';
            $content = $response->message()->getContent();

            return is_string($content) ? $content : '';
        } catch (NeuronException $e) {
            $failure = $this->failures->map($e);
            $outcome = 'failed:' . $failure->category->value;

            throw $failure;
        } finally {
            $tokens = max(0, $in) + max(0, $out);
            $this->usage->record(
                $this->actor->actorId(),
                $this->actor->impersonatorId,
                Feature::Writing,
                $provider->name,
                $provider->model,
                $in,
                $out,
                intdiv(hrtime(true) - $started, 1_000_000),
                0,
                $outcome,
            );
        }
    }
}
