<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\VectorAi\Filters\FilterTranslator;
use Kinetis\VectorAi\Testing\ScriptedCalls;
use Kinetis\VectorAi\Testing\ScriptedReplies;
use Kinetis\Testing\TestResponse;
use NeuronAI\Testing\RequestRecord;

/**
 * Plain-language filters against the `fake` provider: script() binds what
 * it answers to each sentence (and to each repair turn), and calls() is
 * every request it was sent, messages, system prompt and the structured
 * call's schema. The `ai` settings start and end at their defaults.
 */
abstract class FiltersTestCase extends AiTestCase
{
    protected ScriptedCalls $calls;

    /** What the fake provider answers now; the container asks for it on every call, so script() can change it. */
    private ScriptedReplies $replies;

    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->calls = new ScriptedCalls();
        $this->replies = new ScriptedReplies([], $this->calls);
        $app->bind(ScriptedReplies::class, fn (): ScriptedReplies => $this->replies, shared: false);
    }

    #[\Override]
    protected function aiConfig(): array
    {
        return ['VECTOR_AI_PROVIDERS' => 'test-fake', 'VECTOR_AI_TEST_FAKE_TYPE' => 'fake'];
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'ai')->delete();
        $this->script([]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'ai')->delete();
        parent::tearDown();
    }

    /**
     * What the fake provider answers from now on: the sentence (or the
     * repair turn's text, repair()) => the answer, an array being sent as
     * JSON, or, a list, the turns of a run that calls tools (ScriptedReplies::of());
     * and what it throws instead for each message in $failures.
     *
     * @param array<string, string|array<array-key, mixed>> $replies
     * @param array<string, \Throwable> $failures
     */
    protected function script(array $replies, array $failures = []): void
    {
        $this->calls = new ScriptedCalls();
        $this->replies = ScriptedReplies::of(
            // an answer object is sent as JSON; a list is the turns of a tool-using run
            array_map(static fn (string|array $r): string|array => is_string($r) || array_is_list($r) ? $r : json_encode($r, JSON_THROW_ON_ERROR), $replies),
            $this->calls,
            $failures,
        );
    }

    /** The repair turn's text for the list's refusal $message. */
    protected static function repair(string $message): string
    {
        return sprintf(FilterTranslator::REPAIR_PROMPT, $message);
    }

    /**
     * An answer in the shape FilterSchema asks for.
     *
     * @param list<array<string, mixed>> $conditions
     * @param list<list<list<array<string, mixed>>>> $anyOf
     * @return array<string, mixed>
     */
    protected static function answer(array $conditions, array $anyOf = [], ?string $q = null, string $explanation = 'Explained.'): array
    {
        return ['conditions' => $conditions, 'anyOf' => $anyOf, 'q' => $q, 'explanation' => $explanation];
    }

    /** @param array<string, mixed> $extra */
    protected function ask(string $resource, string $text, array $extra = [], int $actor = 1): TestResponse
    {
        return $this->client->post("/vector/ai/filters/{$resource}", ['text' => $text, ...$extra], $this->aiHeaders($actor));
    }

    /** @return list<RequestRecord> */
    protected function calls(): array
    {
        return $this->calls->all();
    }

    /** Everything a recorded call carried to the model: system prompt, messages and schema, as one text. */
    protected static function sent(RequestRecord $call): string
    {
        $text = (string) $call->systemPrompt?->getContent();

        foreach ($call->messages as $message) {
            foreach ($message->getTextBlocks() as $block) {
                $text .= "\n" . $block->content;
            }
        }

        return $text . "\n" . json_encode($call->structuredSchema, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $values */
    protected function settings(array $values): void
    {
        $this->client->request('PUT', '/vector/settings/ai', body: $values, headers: $this->adminHeaders())->assertOk();
    }

    protected function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}
