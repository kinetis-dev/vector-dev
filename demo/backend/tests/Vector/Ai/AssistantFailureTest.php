<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Auth\DemoActor;
use App\Vector\Widgets\LowStockTable;
use Generator;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Runtime\ClientConnectionInterface;
use Kinetis\Runtime\UnobservableClientConnection;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Widgets\VectorWidget;
use Kinetis\Vector\Widgets\WidgetRequest;
use Kinetis\Vector\Widgets\WidgetResult;
use Kinetis\VectorAi\Assistant\Assistant;
use Kinetis\VectorAi\Assistant\AssistantLedger;
use Kinetis\VectorAi\Assistant\DepartureSignal;
use Kinetis\VectorAi\Assistant\RunawayToolCallsException;
use Kinetis\VectorAi\Assistant\ToolBudget;
use Kinetis\VectorAi\Providers\ResolvedProvider;
use Kinetis\VectorAi\Testing\ScriptedProvider;
use Kinetis\VectorAi\Testing\ScriptedReplies;
use Kinetis\VectorAi\Usage\UsageLedger;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\Providers\ProviderResponse;
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
 * attached to the log line only under APP_ENV=development. Once the
 * browser has gone, a failure writes no error frame; the stop its leaving
 * caused is logged at info, any other failure as before.
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
            $response = $assistant->stream(new ResolvedProvider(new FailingMidStream(), 'test-fake', 'fake', 100), new UnobservableClientConnection(), 'Be brief.', 'c_fail', [['role' => 'user', 'content' => self::QUESTION]]);
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
        // failed after output streamed: the provider may have billed it, so it is estimated
        self::assertSame('failed:refused' . AssistantLedger::ESTIMATED, $rows[0]['outcome']);
        self::assertGreaterThan(0, (int) $rows[0]['input_tokens']);
        self::assertGreaterThan(0, (int) $rows[0]['output_tokens'], '"Low on" was streamed back');

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
            $body = self::emit($assistant->stream(new ResolvedProvider(new ScriptedProvider($replies), 'test-fake', 'fake', 100), new UnobservableClientConnection(), 'Be brief.', 'c_bug', [['role' => 'user', 'content' => self::QUESTION]]));
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

    public function testSpec7ATypeErrorInAToolAfterTheBrowserLeftIsLoggedAsAnErrorNotClientGone(): void
    {
        $logger = new RecordingLogger();
        [$assistant, $scope] = $this->assistant($logger, 9);
        $replies = ScriptedReplies::of([self::QUESTION => self::rounds([[['name' => 'vector_widget', 'arguments' => ['widget' => $this->lowStockWidget(), 'dashboard' => 'main']]]], 'Never answered.')]);
        $connection = new SwitchableClientConnection();

        try {
            // the browser leaves while the widget runs, and the widget has a bug
            $scope->instance(LowStockTable::class, new BrokenAfterLeaving($connection));
            $body = self::emit($assistant->stream(new ResolvedProvider(new ScriptedProvider($replies), 'test-fake', 'fake', 100), $connection, 'Be brief.', 'c_gone', [['role' => 'user', 'content' => self::QUESTION]]));
        } finally {
            $scope->dispose();
        }

        self::assertTrue($connection->gone);
        self::assertSame([], self::ofType(self::frames($body), 'error'), 'no error frame is written to a browser that left');
        self::assertNull($logger->find('An assistant run ended after the browser left.'), 'a bug is no stop the browser caused');

        $failed = $logger->find('An assistant run failed.');
        self::assertNotNull($failed);
        self::assertSame(LogLevel::ERROR, $failed['level'], 'a bug is an error, whoever is left to see it');
        self::assertSame('error', $failed['context']['category']);
        self::assertSame(\TypeError::class, $failed['context']['exception_class']);
        self::assertStringNotContainsString(BrokenWidget::SECRET, json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function testSpec1TheBrowserGoneBeforeTheFirstStepSendsNoRequestAndChargesNothing(): void
    {
        $logger = new RecordingLogger();
        [$assistant, $scope] = $this->assistant($logger, 21);
        $connection = new SwitchableClientConnection();
        $connection->gone = true;
        $provider = new StoppableFake();

        try {
            $body = self::emit($assistant->stream(new ResolvedProvider($provider, 'test-fake', 'fake', 100), $connection, 'Be brief.', 'c_s1', [['role' => 'user', 'content' => self::QUESTION]], new DepartureSignal($connection)));
        } finally {
            $scope->dispose();
        }

        self::assertSame('', $body, 'nothing written, the run not even started');
        self::assertSame(0, $provider->requests, 'no provider request');
        self::assertSame([], $this->usageRows(21), 'no usage row');
        $gone = $logger->find('An assistant run ended after the browser left.');
        self::assertNotNull($gone);
        self::assertSame(LogLevel::INFO, $gone['level']);
        self::assertSame(['client_gone', 0], [$gone['context']['outcome'], $gone['context']['steps']]);
        self::assertNull($logger->find('An AI provider call failed.'));
    }

    public function testSpec2AnAnswerStoppedMidwayIsChargedTheReportedInputAndTheStreamedOutput(): void
    {
        $logger = new RecordingLogger();
        [$assistant, $scope] = $this->assistant($logger, 22);
        $connection = new SwitchableClientConnection();
        $signal = new DepartureSignal($connection);
        // as Anthropic reports a stopped stream: the input from message_start, the output never past its first token
        $provider = new StoppableFake(signal: $signal, connection: $connection, leaveAfter: 3, usage: new Usage(1000, 1));

        try {
            self::emit($assistant->stream(new ResolvedProvider($provider, 'test-fake', 'fake', 100), $connection, 'Be brief.', 'c_s2', [['role' => 'user', 'content' => self::QUESTION]], $signal));
        } finally {
            $scope->dispose();
        }

        self::assertTrue($signal->fired(), 'the stream asked, and was stopped');
        $rows = $this->usageRows(22);
        self::assertCount(1, $rows);
        self::assertSame('client_gone' . AssistantLedger::ESTIMATED, $rows[0]['outcome']);
        self::assertSame(1000, (int) $rows[0]['input_tokens'], 'the input reported is kept');
        self::assertGreaterThanOrEqual((int) ceil(strlen(StoppableFake::streamed(3)) / AssistantLedger::ESTIMATE_BYTES), (int) $rows[0]['output_tokens'], 'the larger of 1 and what streamed back');
        self::assertSame([], self::levels($logger, [LogLevel::WARNING, LogLevel::ERROR]), 'a departure is no provider failure');
        self::assertSame('client_gone', $logger->find('An assistant run ended after the browser left.')['context']['outcome'] ?? null);
    }

    public function testSpec3AStreamStoppedBeforeAnyTextIsADepartureEstimatedWithNoWarning(): void
    {
        $logger = new RecordingLogger();
        [$assistant, $scope] = $this->assistant($logger, 23);
        $connection = new SwitchableClientConnection();
        $signal = new DepartureSignal($connection);
        $provider = new StoppableFake(signal: $signal, connection: $connection, leaveAfter: 0);

        try {
            self::emit($assistant->stream(new ResolvedProvider($provider, 'test-fake', 'fake', 100), $connection, 'Be brief.', 'c_s3', [['role' => 'user', 'content' => self::QUESTION]], $signal));
        } finally {
            $scope->dispose();
        }

        $rows = $this->usageRows(23);
        self::assertCount(1, $rows);
        self::assertSame('client_gone' . AssistantLedger::ESTIMATED, $rows[0]['outcome']);
        self::assertGreaterThan(0, (int) $rows[0]['input_tokens'], 'the request was sent');
        self::assertSame([], self::levels($logger, [LogLevel::WARNING, LogLevel::ERROR]));
        $gone = $logger->find('An assistant run ended after the browser left.');
        self::assertNotNull($gone);
        self::assertSame(LogLevel::INFO, $gone['level']);
        self::assertSame(ProviderException::class, $gone['context']['exception_class']);
    }

    public function testSpec4AProviderRefusalWhileTheBrowserIsGoneButTheSignalNotFiredIsAFailure(): void
    {
        $logger = new RecordingLogger();
        [$assistant, $scope] = $this->assistant($logger, 24);
        $connection = new SwitchableClientConnection();
        $signal = new DepartureSignal($connection);
        // a Bedrock call is never stopped: it asks no signal, and its refusal is its own
        $provider = new RefusesLikeBedrock();
        $provider->connection = $connection;

        try {
            $body = self::emit($assistant->stream(new ResolvedProvider($provider, 'test-fake', 'fake', 100), $connection, 'Be brief.', 'c_s4', [['role' => 'user', 'content' => self::QUESTION]], $signal));
        } finally {
            $scope->dispose();
        }

        self::assertFalse($signal->fired());
        self::assertSame([], self::ofType(self::frames($body), 'error'), 'nobody reads an error frame');
        self::assertNull($logger->find('An assistant run ended after the browser left.'));
        $failed = $logger->find('An assistant run failed.');
        self::assertNotNull($failed);
        self::assertSame(LogLevel::WARNING, $failed['level']);
        self::assertNotNull($logger->find('An AI provider call failed.'));

        $rows = $this->usageRows(24);
        self::assertCount(1, $rows);
        self::assertSame(['failed:refused', 0, 0], [$rows[0]['outcome'], (int) $rows[0]['input_tokens'], (int) $rows[0]['output_tokens']]);
    }

    public function testSpec5AStreamCutAfterSomeTextIsAFailureEstimated(): void
    {
        $logger = new RecordingLogger();
        [$assistant, $scope] = $this->assistant($logger, 25);
        $connection = new SwitchableClientConnection();
        $signal = new DepartureSignal($connection);
        // the browser is gone by the time the cut surfaces, but the stream was cut, not stopped: it never asked the signal
        $provider = new CutAfterText();
        $provider->connection = $connection;

        try {
            $body = self::emit($assistant->stream(new ResolvedProvider($provider, 'test-fake', 'fake', 100), $connection, 'Be brief.', 'c_s5', [['role' => 'user', 'content' => self::QUESTION]], $signal));
        } finally {
            $scope->dispose();
        }

        self::assertTrue($connection->gone);
        self::assertFalse($signal->fired(), 'nothing stopped the stream');
        self::assertNull($logger->find('An assistant run ended after the browser left.'), 'a cut is no departure, whoever is left');
        self::assertSame(LogLevel::WARNING, $logger->find('An assistant run failed.')['level'] ?? null, 'the provider\'s failure, at warning');
        self::assertSame([], self::ofType(self::frames($body), 'error'), 'no error frame is written to a browser that left');
        $rows = $this->usageRows(25);
        self::assertCount(1, $rows);
        self::assertSame('failed:refused' . AssistantLedger::ESTIMATED, $rows[0]['outcome']);
        self::assertGreaterThan(0, (int) $rows[0]['output_tokens']);
    }

    public function testSpec6ACallThatNeverConnectedCostsNothingHoweverOftenItIsRetried(): void
    {
        $logger = new RecordingLogger();
        [$assistant, $scope] = $this->assistant($logger, 26);

        try {
            $usage = $scope->get(UsageLedger::class);
            self::assertInstanceOf(UsageLedger::class, $usage);
            $actor = self::ACTOR_PREFIX . '26';
            $before = $usage->remaining($actor, 200000);

            for ($i = 0; $i < 3; $i++) {
                self::emit($assistant->stream(new ResolvedProvider(new NeverConnects(), 'test-fake', 'fake', 100), new UnobservableClientConnection(), 'Be brief.', "c_s6_{$i}", [['role' => 'user', 'content' => self::QUESTION]]));
            }

            self::assertSame($before, $usage->remaining($actor, 200000), 'three retries spent nothing of the budget');
        } finally {
            $scope->dispose();
        }

        $rows = $this->usageRows(26);
        self::assertCount(3, $rows);

        foreach ($rows as $row) {
            self::assertSame(['failed:unavailable', 0, 0], [$row['outcome'], (int) $row['input_tokens'], (int) $row['output_tokens']]);
        }
    }

    public function testSpec8AStreamStoppedCleanlyIsClientGoneNeverAnswered(): void
    {
        $logger = new RecordingLogger();
        [$assistant, $scope] = $this->assistant($logger, 28);
        $connection = new SwitchableClientConnection();
        $signal = new DepartureSignal($connection);
        $provider = new StoppableFake(signal: $signal, connection: $connection, leaveAfter: 1);

        try {
            self::emit($assistant->stream(new ResolvedProvider($provider, 'test-fake', 'fake', 100), $connection, 'Be brief.', 'c_s8', [['role' => 'user', 'content' => self::QUESTION]], $signal));
        } finally {
            $scope->dispose();
        }

        self::assertTrue($signal->fired());
        self::assertNull($logger->find('An assistant run ended.'), 'nobody was there to be answered');
        self::assertNull($logger->find('An assistant run failed.'));
        $gone = $logger->find('An assistant run ended after the browser left.');
        self::assertNotNull($gone);
        self::assertSame([LogLevel::INFO, 'client_gone'], [$gone['level'], $gone['context']['outcome']]);
        self::assertArrayNotHasKey('exception_class', $gone['context'], 'nothing failed');
        $rows = $this->usageRows(28);
        self::assertCount(1, $rows);
        self::assertSame('client_gone' . AssistantLedger::ESTIMATED, $rows[0]['outcome'], 'no usage reported: both sides estimated');
    }

    public function testACleanEndIsAnsweredWithTheReportedUsage(): void
    {
        $logger = new RecordingLogger();
        [$assistant, $scope] = $this->assistant($logger, 29);
        $connection = new SwitchableClientConnection();
        $signal = new DepartureSignal($connection);

        try {
            self::emit($assistant->stream(new ResolvedProvider(new StoppableFake(signal: $signal, connection: $connection, usage: new Usage(40, 3)), 'test-fake', 'fake', 100), $connection, 'Be brief.', 'c_done', [['role' => 'user', 'content' => self::QUESTION]], $signal));
        } finally {
            $scope->dispose();
        }

        self::assertSame('answered', $logger->find('An assistant run ended.')['context']['outcome'] ?? null);
        $rows = $this->usageRows(29);
        self::assertSame([['answered', 40, 3]], array_map(static fn (array $r): array => [$r['outcome'], (int) $r['input_tokens'], (int) $r['output_tokens']], $rows));
    }

    public function testAStoppedCallWhoseUsageArrivedIsRecordedAsReportedNotEstimatedToo(): void
    {
        $logger = new RecordingLogger();
        [$assistant, $scope] = $this->assistant($logger, 12);
        $connection = new SwitchableClientConnection();
        $provider = new StoppedCleanlyByLeaving();
        $provider->connection = $connection;
        // as Anthropic reports it: the input up front, the output so far
        $provider->usage = new Usage(321, 7);

        try {
            self::emit($assistant->stream(new ResolvedProvider($provider, 'test-fake', 'fake', 100), $connection, 'Be brief.', 'c_reported', [['role' => 'user', 'content' => self::QUESTION]]));
        } finally {
            $scope->dispose();
        }

        $rows = $this->usageRows(12);
        self::assertCount(1, $rows, 'one row a call, never an estimate beside it');
        self::assertSame('client_gone', $rows[0]['outcome']);
        self::assertSame([321, 7], [(int) $rows[0]['input_tokens'], (int) $rows[0]['output_tokens']]);
    }

    public function testACallInFlightWhenTheBrowserLeftIsEstimated(): void
    {
        $logger = new RecordingLogger();
        [$assistant, $scope] = $this->assistant($logger, 13);
        $written = '';
        // the browser leaves once it has been sent the first words; this provider, unlike a stoppable one, talks on
        $connection = new LeavesOnceItSaw(static function () use (&$written): bool {
            return str_contains($written, 'text-delta');
        });

        try {
            $response = $assistant->stream(new ResolvedProvider(new KeepsTalking(), 'test-fake', 'fake', 100), $connection, 'Be brief.', 'c_inflight', [['role' => 'user', 'content' => self::QUESTION]]);
            ob_start(static function (string $chunk) use (&$written): string {
                $written .= $chunk;

                return '';
            });

            try {
                ($response->getEmitter())();
            } finally {
                ob_end_flush();
            }
        } finally {
            $scope->dispose();
        }

        self::assertStringNotContainsString('word 50', $written, 'the run was read no further');
        $rows = $this->usageRows(13);
        self::assertCount(1, $rows);
        self::assertSame('client_gone' . AssistantLedger::ESTIMATED, $rows[0]['outcome']);
        self::assertGreaterThan(0, (int) $rows[0]['input_tokens']);
        self::assertGreaterThan(0, (int) $rows[0]['output_tokens']);
        self::assertSame('client_gone', $logger->find('An assistant run ended after the browser left.')['context']['outcome'] ?? null);
    }

    public function testACallWhoseUsageWasReportedIsNeverEstimated(): void
    {
        $this->script([self::QUESTION => self::rounds([[['name' => 'vector_resources']]], 'There are products#1.')]);
        [$assistant, $scope] = $this->assistant(new RecordingLogger(), 14);

        try {
            $resolver = $scope->get(\Kinetis\VectorAi\Providers\ProviderResolver::class);
            self::assertInstanceOf(\Kinetis\VectorAi\Providers\ProviderResolver::class, $resolver);
            $user = $scope->get(CurrentUserInterface::class);
            self::assertInstanceOf(CurrentUserInterface::class, $user);
            self::emit($assistant->stream($resolver->for(\Kinetis\VectorAi\Feature::Assistant, $user), new UnobservableClientConnection(), 'Be brief.', 'c_real', [['role' => 'user', 'content' => self::QUESTION]]));
        } finally {
            $scope->dispose();
        }

        $rows = $this->usageRows(14);
        self::assertSame(['answered', 'answered'], array_column($rows, 'outcome'), 'one row a call, as reported');
        self::assertCount(count($this->calls()), $rows);
        self::assertGreaterThan(0, (int) $rows[0]['input_tokens']);
    }

    public function testAModelCallingPastItsBudgetIsLoggedAsAnError(): void
    {
        $logger = new RecordingLogger();
        [$assistant, $scope] = $this->assistant($logger, 7);
        // the default budget of 8, then RUNAWAY refusals, then one more
        $calls = array_fill(0, 8 + ToolBudget::RUNAWAY + 1, ['name' => 'vector_resources']);
        $replies = ScriptedReplies::of([self::QUESTION => self::rounds([$calls], 'Never answered.')]);

        try {
            $body = self::emit($assistant->stream(new ResolvedProvider(new ScriptedProvider($replies), 'test-fake', 'fake', 100), new UnobservableClientConnection(), 'Be brief.', 'c_runaway', [['role' => 'user', 'content' => self::QUESTION]]));
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

        self::assertSame(['text' => 'There are products#1.', 'toolCalls' => ['vector_resources'], 'approvals' => []], $answer);
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

    /**
     * The messages logged at any of $levels.
     *
     * @param list<string> $levels
     * @return list<string>
     */
    private static function levels(RecordingLogger $logger, array $levels): array
    {
        return array_values(array_map(
            static fn (array $r): string => "{$r['level']}: {$r['message']}",
            array_filter($logger->records, static fn (array $r): bool => in_array($r['level'], $levels, true)),
        ));
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

/** A connection whose browser leaves when a test says so. */
final class SwitchableClientConnection implements ClientConnectionInterface
{
    public bool $gone = false;

    #[\Override]
    public function isGone(): bool
    {
        return $this->gone;
    }
}

/**
 * A provider stream as StoppableHttpClient reads one: before every chunk it
 * asks the run's DepartureSignal, as StoppableStream::eof() asks
 * $shouldStop, and once that answers true it ends as
 * HandleEarlyStreamEnd::earlyEndResponse() ends a stopped stream — with
 * the text so far and the usage reported so far, or, before any text, the
 * ProviderException it raises. The browser leaves after $leaveAfter
 * chunks, while the stream is being read.
 */
final class StoppableFake extends FakeAIProvider
{
    /** What it streams, chunk by chunk. */
    public const array CHUNKS = ['Low on stock: ', 'products#3 ', 'and products#9, ', 'both under five.'];

    /** How many requests it was sent. */
    public int $requests = 0;

    public function __construct(
        private readonly ?DepartureSignal $signal = null,
        private readonly ?SwitchableClientConnection $connection = null,
        private readonly ?int $leaveAfter = null,
        private readonly ?Usage $usage = null,
    ) {
        parent::__construct();
    }

    /** The text of its first $chunks chunks. */
    public static function streamed(int $chunks): string
    {
        return implode('', array_slice(self::CHUNKS, 0, $chunks));
    }

    /** @return Generator<int, TextChunk, mixed, ProviderResponse> */
    #[\Override]
    public function stream(Message ...$messages): Generator
    {
        $this->requests++;

        return $this->read();
    }

    /** @return Generator<int, TextChunk, mixed, ProviderResponse> */
    private function read(): Generator
    {
        $text = '';

        foreach (self::CHUNKS as $i => $chunk) {
            if ($i === $this->leaveAfter && $this->connection !== null) {
                $this->connection->gone = true;
            }

            if ($this->signal?->shouldStop() === true) {
                if ($text === '') {
                    throw new ProviderException('The stream was stopped before the answer started.');
                }

                return $this->answer($text, StoppableHttpClient::STOP_REASON);
            }

            $text .= $chunk;

            yield new TextChunk('msg_1', $chunk);
        }

        return $this->answer($text, null);
    }

    private function answer(string $text, ?string $stopReason): ProviderResponse
    {
        $message = (new AssistantMessage($text))->setId('msg_1');

        if ($stopReason !== null) {
            $message->setStopReason($stopReason);
        }

        if ($this->usage !== null) {
            $message->setUsage($this->usage);
        }

        return new ProviderResponse(message: $message);
    }
}

/** A Bedrock call refused while the browser happens to be gone: it never asks a signal — Bedrock is never stopped — and its refusal is a ProviderException. */
final class RefusesLikeBedrock extends FakeAIProvider
{
    public ?SwitchableClientConnection $connection = null;

    /** @return Generator<int, TextChunk, mixed, never> */
    #[\Override]
    public function stream(Message ...$messages): Generator
    {
        if ($this->connection !== null) {
            $this->connection->gone = true;
        }

        throw new ProviderException('Bedrock answered HTTP 403: not authorized');

        yield new TextChunk('msg_1', ''); // @phpstan-ignore deadCode.unreachable
    }
}

/** A stream the network cut after some text, while the browser happened to leave: not stopped by anyone. */
final class CutAfterText extends FakeAIProvider
{
    /** The browser's connection, which the cut finds gone: it never asks a signal, so nothing stopped it. */
    public ?SwitchableClientConnection $connection = null;

    /** @return Generator<int, TextChunk, mixed, never> */
    #[\Override]
    public function stream(Message ...$messages): Generator
    {
        yield new TextChunk('msg_1', 'Low on stock: ');

        if ($this->connection !== null) {
            $this->connection->gone = true;
        }

        throw new ProviderException('The stream ended before the answer was complete.');
    }
}

/** A call that never reached the provider: no connection, no response. */
final class NeverConnects extends FakeAIProvider
{
    /** @return Generator<int, TextChunk, mixed, never> */
    #[\Override]
    public function stream(Message ...$messages): Generator
    {
        throw new HttpException('Network error during POST https://provider.invalid/v1: connection refused');

        yield new TextChunk('msg_1', ''); // @phpstan-ignore deadCode.unreachable
    }
}

/** A provider whose stream StoppableHttpClient stops when the browser leaves: it ends cleanly, with the text so far and no usage. */
final class StoppedCleanlyByLeaving extends FakeAIProvider
{
    public ?SwitchableClientConnection $connection = null;

    /** What the provider reported before it was stopped, if anything. */
    public ?Usage $usage = null;

    /** @return Generator<int, TextChunk, mixed, ProviderResponse> */
    #[\Override]
    public function stream(Message ...$messages): Generator
    {
        yield new TextChunk('msg_1', 'Low ');

        if ($this->connection !== null) {
            $this->connection->gone = true;
        }

        $message = (new AssistantMessage('Low '))->setStopReason(StoppableHttpClient::STOP_REASON)->setId('msg_1');

        if ($this->usage !== null) {
            $message->setUsage($this->usage);
        }

        return new ProviderResponse(message: $message);
    }
}

/** A provider that streams fifty words and would go on, as a call no stop reaches does. */
final class KeepsTalking extends FakeAIProvider
{
    /** @return Generator<int, TextChunk, mixed, ProviderResponse> */
    #[\Override]
    public function stream(Message ...$messages): Generator
    {
        for ($i = 1; $i <= 50; $i++) {
            yield new TextChunk('msg_1', "word {$i} ");
        }

        return new ProviderResponse(message: (new AssistantMessage('…'))->setId('msg_1'));
    }
}

/** A widget with a bug, run while the browser leaves. */
final class BrokenAfterLeaving extends VectorWidget
{
    public function __construct(private readonly SwitchableClientConnection $connection) {}

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
        $this->connection->gone = true;

        throw new \TypeError(BrokenWidget::SECRET);
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
