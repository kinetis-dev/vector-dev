<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Auth\DemoActor;
use App\Vector\Widgets\LowStockTable;
use Generator;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Widgets\VectorWidget;
use Kinetis\Vector\Widgets\WidgetRequest;
use Kinetis\Vector\Widgets\WidgetResult;
use Kinetis\VectorAi\Assistant\Assistant;
use Kinetis\VectorAi\Assistant\RunawayToolCallsException;
use Kinetis\VectorAi\Assistant\ToolBudget;
use Kinetis\VectorAi\Providers\ResolvedProvider;
use Kinetis\VectorAi\Testing\ScriptedProvider;
use Kinetis\VectorAi\Testing\ScriptedReplies;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Testing\FakeAIProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * A provider that fails after the stream has begun: the browser gets the
 * text so far and the adapter's neutral error frame, the failed call is a
 * usage row, and the failure is logged by category — a warning when the
 * provider failed, an error when the run itself did — never the
 * provider's words, which can quote the prompt. The exception itself is
 * attached to the log line only under APP_ENV=development.
 */
final class AssistantFailureTest extends AssistantTestCase
{
    private const string SECRET = 'the provider quoted the prompt: Which products';

    /** @return array<string, array{string, bool}> */
    public static function environments(): array
    {
        return ['production' => ['production', false], 'development' => ['development', true]];
    }

    /** @return array<string, string> */
    #[\Override]
    protected function configOverrides(): array
    {
        $environment = $this->providedData()[0] ?? 'production';
        self::assertIsString($environment);

        return [...parent::configOverrides(), 'APP_ENV' => $environment];
    }

    #[DataProvider('environments')]
    public function testAMidStreamFailureEndsWithTheErrorFrameAndIsLoggedByCategory(string $environment, bool $attached): void
    {
        $logger = new RecordingLogger();
        [$assistant, $scope] = $this->assistant($logger, 5);

        try {
            $response = $assistant->stream(new ResolvedProvider(new FailingMidStream(), 'test-fake', 'fake', 100), 'Be brief.', 'c_fail', [['role' => 'user', 'content' => self::QUESTION]]);
            $body = self::emit($response);
        } finally {
            $scope->dispose();
        }

        $frames = self::frames($body);
        self::assertSame('Low on', self::text($frames), 'the text streamed before the failure');
        self::assertSame(['type' => 'error', 'errorText' => 'The run failed.'], self::last($frames));
        self::assertSame([], self::ofType($frames, 'finish'));
        self::assertStringNotContainsString(self::SECRET, $body);

        $rows = $this->usageRows(5);
        self::assertCount(1, $rows);
        self::assertSame('failed:refused', $rows[0]['outcome']);

        $failed = $logger->find('An assistant run failed.');
        self::assertNotNull($failed);
        self::assertSame('refused', $failed['context']['category']);
        self::assertSame(LogLevel::WARNING, $failed['level'], 'a provider\'s failure is a warning');
        self::assertSame('test-fake', $failed['context']['provider']);
        self::assertSame($attached, array_key_exists('exception', $failed['context']), "the exception under {$environment}");
        self::assertNotNull($logger->find('An AI provider call failed.'));

        foreach ($logger->records as $record) {
            $context = $record['context'];
            unset($context['exception']);
            self::assertStringNotContainsString(self::SECRET, $record['message'] . json_encode($context), 'a log line carried the provider\'s words');
            self::assertStringNotContainsString(self::QUESTION, $record['message'] . json_encode($context), 'a log line carried the conversation');
        }
    }

    public function testABugInAToolIsLoggedWithItsClassAndPlaceButNeverItsMessage(): void
    {
        $logger = new RecordingLogger();
        [$assistant, $scope] = $this->assistant($logger, 8);
        $replies = ScriptedReplies::of([self::QUESTION => self::rounds([[['name' => 'vector_widget', 'arguments' => ['widget' => $this->lowStockWidget(), 'dashboard' => 'main']]]], 'Never answered.')]);

        try {
            // the low-stock table, as the dashboard resolves it from this request's scope, has a bug
            $scope->instance(LowStockTable::class, new BrokenWidget());
            $body = self::emit($assistant->stream(new ResolvedProvider(new ScriptedProvider($replies), 'test-fake', 'fake', 100), 'Be brief.', 'c_bug', [['role' => 'user', 'content' => self::QUESTION]]));
        } finally {
            $scope->dispose();
        }

        self::assertSame(['type' => 'error', 'errorText' => 'The run failed.'], self::last(self::frames($body)));
        self::assertStringNotContainsString(BrokenWidget::SECRET, $body);

        $failed = $logger->find('An assistant run failed.');
        self::assertNotNull($failed, 'the run\'s failure was logged');
        $context = $failed['context'];
        self::assertSame('error', $context['category'], 'no provider failure: the run failed in a tool');
        self::assertSame(LogLevel::ERROR, $failed['level'], 'a bug is an error');
        self::assertSame(\LogicException::class, $context['exception_class']);
        self::assertSame(BrokenWidget::THROWN_AT, $context['exception_at']);
        self::assertArrayNotHasKey('exception', $context, 'the exception itself only under APP_ENV=development');
        self::assertStringNotContainsString(BrokenWidget::SECRET, json_encode($logger->records, JSON_THROW_ON_ERROR), 'a log line carried the exception\'s message');
    }

    public function testAModelCallingPastItsBudgetIsLoggedAsAnError(): void
    {
        $logger = new RecordingLogger();
        [$assistant, $scope] = $this->assistant($logger, 7);
        // the default budget of 8, then RUNAWAY refusals, then one more
        $calls = array_fill(0, 8 + ToolBudget::RUNAWAY + 1, ['name' => 'vector_resources']);
        $replies = ScriptedReplies::of([self::QUESTION => self::rounds([$calls], 'Never answered.')]);

        try {
            $body = self::emit($assistant->stream(new ResolvedProvider(new ScriptedProvider($replies), 'test-fake', 'fake', 100), 'Be brief.', 'c_runaway', [['role' => 'user', 'content' => self::QUESTION]]));
        } finally {
            $scope->dispose();
        }

        self::assertSame(['type' => 'error', 'errorText' => 'The run failed.'], self::last(self::frames($body)));
        $failed = $logger->find('An assistant run failed.');
        self::assertNotNull($failed);
        self::assertSame('runaway_tools', $failed['context']['category']);
        self::assertSame(LogLevel::ERROR, $failed['level']);
        self::assertSame(RunawayToolCallsException::class, $failed['context']['exception_class']);
        self::assertArrayHasKey('exception_at', $failed['context']);
        self::assertArrayNotHasKey('exception', $failed['context']);
    }

    public function testARunThatEndsIsOneLogLineWithoutTheConversation(): void
    {
        $logger = new RecordingLogger();
        $this->script([self::QUESTION => self::rounds([[['name' => 'vector_resources']]], 'There are products#1.')]);
        [$assistant, $scope] = $this->assistant($logger, 6);

        try {
            $resolver = $scope->get(\Kinetis\VectorAi\Providers\ProviderResolver::class);
            self::assertInstanceOf(\Kinetis\VectorAi\Providers\ProviderResolver::class, $resolver);
            $user = $scope->get(CurrentUserInterface::class);
            self::assertInstanceOf(CurrentUserInterface::class, $user);
            $answer = $assistant->answer($resolver->for(\Kinetis\VectorAi\Feature::Assistant, $user), 'Be brief.', 'c_ok', [['role' => 'user', 'content' => self::QUESTION]]);
        } finally {
            $scope->dispose();
        }

        self::assertSame(['text' => 'There are products#1.', 'toolCalls' => ['vector_resources']], $answer);
        $ended = $logger->find('An assistant run ended.');
        self::assertNotNull($ended);
        self::assertSame(['vector_resources'], $ended['context']['tools']);
        self::assertSame(1, $ended['context']['toolCalls']);
        self::assertSame('answered', $ended['context']['outcome']);
        self::assertGreaterThan(0, $ended['context']['inputTokens']);
        $logged = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::QUESTION, $logged);
        self::assertStringNotContainsString('products#1', $logged);
        self::assertStringNotContainsString('Data returned by the admin', $logged);
    }

    /** The name the main dashboard gives the low-stock table. */
    private function lowStockWidget(): string
    {
        $registry = $this->app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);

        foreach ($registry->dashboard('main')['widgets'] as $widget) {
            if ($widget['class'] === LowStockTable::class) {
                return (string) $widget['name'];
            }
        }

        self::fail('The main dashboard has no low-stock table.');
    }

    /** @return array{Assistant, RequestScope} */
    private function assistant(LoggerInterface $logger, int $actor): array
    {
        $scope = $this->app->createRequestScope();
        $scope->instance(CurrentUserInterface::class, new DemoActor((int) (self::ACTOR_PREFIX . str_pad((string) $actor, 2, '0', STR_PAD_LEFT)), 'Test', true));
        $scope->instance(LoggerInterface::class, $logger);
        $assistant = $scope->get(Assistant::class);
        self::assertInstanceOf(Assistant::class, $assistant);

        return [$assistant, $scope];
    }
}

/** A provider whose stream writes a little, then fails as a refusing provider does. */
final class FailingMidStream extends FakeAIProvider
{
    /** @return Generator<int, TextChunk, mixed, never> */
    #[\Override]
    public function stream(Message ...$messages): Generator
    {
        yield new TextChunk('msg_1', 'Low ');
        yield new TextChunk('msg_1', 'on');

        throw new ProviderException('the provider quoted the prompt: Which products are low on stock?');
    }
}

/** A widget with a bug: it throws something no refusal is made of, its message quoting data. */
final class BrokenWidget extends VectorWidget
{
    public const string SECRET = 'stock of SKU-SECRET-42 is -3';

    public const string THROWN_AT = __FILE__ . ':' . (__LINE__ + 17);

    #[\Override]
    public function label(): string
    {
        return 'Broken';
    }

    #[\Override]
    public function type(): string
    {
        return self::TABLE;
    }

    #[\Override]
    public function calculate(WidgetRequest $request): WidgetResult
    {
        throw new \LogicException(self::SECRET);
    }
}

/** Every log record, kept for the test to read. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return array{level: mixed, message: string, context: array<string, mixed>}|null */
    public function find(string $message): ?array
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                return $record;
            }
        }

        return null;
    }
}
