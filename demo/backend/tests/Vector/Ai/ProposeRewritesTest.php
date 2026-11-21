<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Auth\DemoActor;
use App\Vector\Actions\TidyOrderNotes;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Testing\TestResponse;
use Kinetis\Vector\Actions\ActionResponse;
use Kinetis\Vector\Actions\ActionRunner;
use Kinetis\Vector\Auth\ActorResolverInterface;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\Vector\Import\CsvReader;
use Kinetis\Vector\Jobs\JobContext;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Store\JobStore;
use Kinetis\VectorAi\Actions\ProposeRewrites;
use Kinetis\VectorAi\Actions\RewriteStore;
use Kinetis\VectorAi\Auth\AiAccessInterface;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Writing\WritingAssistant;
use Kinetis\VectorAi\Writing\WritingOperation;
use NeuronAI\Exceptions\ProviderException;

/**
 * The bulk ProposeRewrites action (the demo's TidyOrderNotes, on orders'
 * notes) against the `fake` provider, run through the sync queue: one
 * writing call per record, one CSV on the job that Vector's own import
 * applies in update mode, no provider call for a chunk delivered again,
 * refusals before any call, and per-record skips, failures, budget and
 * cap. The tests' actors (`9902<n>`) are rebuilt for the job by a
 * resolver of the test's own; the orders' notes are put back after each
 * test, and the audit, action and job rows of those actors removed.
 */
final class ProposeRewritesTest extends WritingTestCase
{
    /** What a record the orders' policy here refuses to update starts with. */
    private const string LOCKED = 'LOCKED ';

    /** The actor AI writing is refused to. */
    private const int NO_WRITING = 5;

    /** @var array<int, ?string> order id => its notes before the test */
    private array $notes = [];

    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $resolver = $app->get(ActorResolverInterface::class);
        self::assertInstanceOf(ActorResolverInterface::class, $resolver);
        $app->instance(ActorResolverInterface::class, new TestActors($resolver));
        $app->instance(AllowAllPolicy::class, new LockedNotesPolicy());
        $app->instance(AiAccessInterface::class, new NoWritingFor(self::ACTOR_PREFIX . str_pad((string) self::NO_WRITING, 2, '0', STR_PAD_LEFT)));

        $registry = $app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $data = $registry->toArray();

        foreach ([
            'orders' => [RewriteOrderStatus::class, LongGuidance::class, TranslateWithoutLocale::class, TidyIntoSmallFile::class, TidyIntoTinyCsv::class],
            'products' => [RewriteProductDescriptions::class],
            'users' => [RewritePasswords::class],
            'tags' => [RewriteTagNames::class],
            'profiles' => [RewriteProfileNames::class],
        ] as $resource => $classes) {
            foreach ($classes as $class) {
                $action = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
                $data['resources'][$resource]['actions'][$action->name()] = [
                    'name' => $action->name(), 'class' => $class, 'label' => $action->label(), 'icon' => null, 'confirmation' => null, 'destructive' => false,
                    'standalone' => false, 'queued' => true, 'chunkSize' => $action->chunkSize(), 'showOnIndex' => true, 'showOnDetail' => false, 'fields' => [],
                ];
            }
        }

        // products' description on the update form but not the detail view, whose serialization the records come from
        foreach ($data['resources']['products']['fields'] as $i => $field) {
            if (is_array($field) && ($field['name'] ?? null) === 'description') {
                $data['resources']['products']['fields'][$i]['views'] = array_values(array_diff($field['views'], ['show']));
            }
        }

        $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->notes as $id => $notes) {
            $this->shop()->table('orders')->where('id', '=', $id)->update(['notes' => $notes]);
        }

        $this->vectorQuery()->table('vector_audit')->where('actor_id', 'LIKE', self::ACTOR_PREFIX . '%')->delete();
        $this->vectorQuery()->table('vector_action_events')->where('actor_id', 'LIKE', self::ACTOR_PREFIX . '%')->delete();
        $this->vectorQuery()->table('vector_notifications')->where('owner_id', 'LIKE', self::ACTOR_PREFIX . '%')->delete();
        $jobs = $this->vectorQuery()->table('vector_jobs')->select('id')->where('actor_id', 'LIKE', self::ACTOR_PREFIX . '%')->get();
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $jobs);

        if ($ids !== []) {
            $this->vectorQuery()->table(RewriteStore::TABLE)->whereIn('job_id', $ids)->delete();
            $this->vectorQuery()->table('vector_jobs')->whereIn('id', $ids)->delete();
        }

        parent::tearDown();
    }

    public function testThreeHundredRecordsMakeOneCsvThatTheImportAppliesAsTheUsersOwnUpdates(): void
    {
        $ids = $this->orders(300);
        $this->scriptTidy($ids);

        $job = $this->runAction('tidy-order-notes', $ids)->assertJsonPath('data.status', 'done')->assertJsonPath('data.hasFile', true)->json();
        self::assertIsArray($job);
        self::assertSame(300, $job['data']['processed']);
        self::assertStringContainsString('for 300 record(s)', (string) $job['data']['result']['message']);
        self::assertSame("orders-notes-proposals-{$job['data']['id']}.csv", $job['data']['fileName']);

        // one call per record, each its own single-shot turn holding only its own text
        $calls = $this->calls();
        self::assertCount(300, $calls);

        foreach ($calls as $call) {
            self::assertSame(1, substr_count(self::sent($call), "The text to transform:\n" . WritingAssistant::OPEN), 'one record\'s text per call');
        }

        // nothing was written; the run's rows outlive finish(), so a failed save of the file loses nothing,
        // and go with the sweep once the job is done with its file
        self::assertSame($this->notesNow($ids), array_map(static fn (int $id): string => self::note($id), array_combine($ids, $ids)));
        $rows = fn (): int => $this->vectorQuery()->table(RewriteStore::TABLE)->where('job_id', '=', (int) $job['data']['id'])->count();
        self::assertSame(300, $rows());
        self::assertGreaterThanOrEqual(300, $this->rewrites()->deleteEnded($this->jobStore()));
        self::assertSame(0, $rows());

        $csv = $this->download((int) $job['data']['id']);
        $reader = CsvReader::fromString($csv);
        self::assertSame(['id', 'notes'], $reader->headers());
        self::assertSame(300, $reader->rowCount());
        $rows = iterator_to_array($reader->rows(), false);
        self::assertSame(array_map(strval(...), $ids), array_column($rows, 'id'));

        // the admin imports it, in update mode keyed by id: 300 updates of theirs, audited, with no origin
        $import = $this->import($csv);
        self::assertSame(['created' => 0, 'failed' => 0, 'updated' => 300], self::sorted($import['data']['result']));
        self::assertSame(array_combine($ids, array_map(static fn (int $id): string => self::tidy($id), $ids)), $this->notesNow($ids));
        $audits = $this->vectorQuery()->table('vector_audit')->where('resource', '=', 'orders')->where('event', '=', 'update')
            ->where('actor_id', '=', self::ACTOR_PREFIX . '01')->get();
        self::assertCount(300, $audits);
        self::assertSame([null], array_values(array_unique(array_column($audits, 'origin'))));
    }

    public function testAChunkDeliveredAgainCallsNoProviderAndTheCsvHoldsEachRecordOnce(): void
    {
        $ids = $this->orders(5);
        $this->scriptTidy($ids);
        $scope = $this->app->createRequestScope();

        try {
            // what RunActionJob does, chunk by chunk, with the first chunk and then all of them delivered again
            $context = $scope->get(JobContext::class);
            self::assertInstanceOf(JobContext::class, $context);
            $jobs = $scope->get(JobStore::class);
            self::assertInstanceOf(JobStore::class, $jobs);
            $jobId = $jobs->create('action', 'orders', 'tidy-order-notes', self::ACTOR_PREFIX . '01');
            $context->actAs(self::ACTOR_PREFIX . '01');
            $runner = $context->get(ActionRunner::class);
            $service = $context->get(ResourceService::class);
            self::assertInstanceOf(ActionRunner::class, $runner);
            self::assertInstanceOf(ResourceService::class, $service);
            $orders = $service->visible($service->resource('orders'));

            $runner->runNow($orders, 'tidy-order-notes', array_slice($ids, 0, 3), [], $jobId);
            self::assertCount(3, $this->calls());
            $runner->runNow($orders, 'tidy-order-notes', array_slice($ids, 0, 3), [], $jobId);
            self::assertCount(3, $this->calls(), 'a chunk delivered again calls no provider');
            $runner->runNow($orders, 'tidy-order-notes', array_slice($ids, 3), [], $jobId);
            $runner->runNow($orders, 'tidy-order-notes', $ids, [], $jobId);
            self::assertCount(5, $this->calls());

            $finished = $runner->finish($orders, 'tidy-order-notes', [], $jobId);
        } finally {
            $scope->dispose();
        }

        self::assertNotNull($finished);
        self::assertSame('download', $finished->type);
        $rows = iterator_to_array(CsvReader::fromString((string) $finished->contents)->rows(), false);
        self::assertSame(array_map(strval(...), $ids), array_column($rows, 'id'), 'each record once, in order');
        self::assertSame(array_map(static fn (int $id): string => self::tidy($id), $ids), array_column($rows, 'notes'));
    }

    public function testAPasswordOrNonTextFieldRefusesTheRunBeforeAnyCall(): void
    {
        $user = (int) $this->shop()->table('users')->orderBy('id', 'ASC')->value('id');
        $this->runAction('rewrite-passwords', [$user], 'users')->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure', 'A password field is never sent to AI.');
        $this->runAction('rewrite-order-status', $this->orders(2))->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure', 'Writing help works on text, Markdown and rich-text fields only.');

        self::assertSame([], $this->calls());
    }

    public function testAFieldOrResourceKeptFromAiRefusesTheRunBeforeAnyCall(): void
    {
        $tag = (int) $this->shop()->table('tags')->orderBy('id', 'ASC')->value('id');
        $this->runAction('rewrite-tag-names', [$tag], 'tags')->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure', 'This field of Tags is kept from AI.');
        $user = (int) $this->shop()->table('users')->orderBy('id', 'ASC')->value('id');
        $this->runAction('rewrite-profile-names', [$user], 'profiles')->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure', 'This field of Profiles is kept from AI.');

        self::assertSame([], $this->calls());
    }

    public function testAUserWithoutAiWritingIsRefused(): void
    {
        $ids = $this->orders(2);
        $this->scriptTidy($ids);

        $this->runAction('tidy-order-notes', $ids, actor: self::NO_WRITING)->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure', 'Writing help is not available to this account.');
        self::assertSame([], $this->calls());
    }

    public function testAMisconfiguredSubclassIsRefusedBeforeAnyCall(): void
    {
        $ids = $this->orders(2);
        $this->runAction('long-guidance', $ids)->assertJsonPath('data.status', 'failed');
        $this->runAction('translate-without-locale', $ids)->assertJsonPath('data.status', 'failed');

        self::assertSame([], $this->calls());
    }

    public function testAFieldNotOnTheDetailViewRefusesTheRunBeforeAnyCall(): void
    {
        $this->runAction('rewrite-product-descriptions', [(int) $this->productId()], 'products')->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure', 'This field of Products is not on its detail view, so its text cannot be read for proposals: show it there, or propose rewrites of another field.');

        self::assertSame([], $this->calls());
    }

    public function testAFailedCallIsCountedByItsCategoryAndTheOtherRecordsAreStillProposed(): void
    {
        $ids = $this->orders(4);
        $secret = 'The provider said: ' . str_repeat('quoted prompt text ', 5);
        $this->scriptWriting(
            [self::note($ids[0]) => self::tidy($ids[0]), self::note($ids[3]) => self::tidy($ids[3])],
            // a Neuron exception WritingAssistant maps, and anything else, which the action maps
            [self::note($ids[1]) => new ProviderException($secret), self::note($ids[2]) => new \RuntimeException($secret)],
        );

        $job = $this->runAction('tidy-order-notes', $ids)->assertJsonPath('data.status', 'done')->assertJsonPath('data.hasFile', true)->json();
        self::assertIsArray($job);
        $message = (string) $job['data']['result']['message'];
        self::assertStringContainsString('for 2 record(s)', $message);
        self::assertStringContainsString('1 AI call(s) failed (refused).', $message);
        self::assertStringContainsString('1 AI call(s) failed (unavailable).', $message);
        self::assertStringNotContainsString('quoted prompt', $message);

        $rows = iterator_to_array(CsvReader::fromString($this->download((int) $job['data']['id']))->rows(), false);
        self::assertSame([
            ['id' => (string) $ids[0], 'notes' => self::tidy($ids[0])],
            ['id' => (string) $ids[3], 'notes' => self::tidy($ids[3])],
        ], $rows);

        // the category only, within the column's 40 characters: never the exception's message
        $failed = $this->vectorQuery()->table(RewriteStore::TABLE)->select('record_key', 'reason')->where('job_id', '=', (int) $job['data']['id'])
            ->where('status', '=', RewriteStore::FAILED)->orderBy('record_key', 'ASC')->get();
        $reasons = array_column($failed, 'reason', 'record_key');
        self::assertSame([(string) $ids[1] => 'refused', (string) $ids[2] => 'unavailable'], $reasons);

        foreach ($reasons as $reason) {
            self::assertLessThanOrEqual(40, mb_strlen((string) $reason));
        }
    }

    public function testAnEmptyAnswerIsAFailureNotAProposal(): void
    {
        $ids = $this->orders(2);
        $this->scriptWriting([self::note($ids[0]) => " \n\t ", self::note($ids[1]) => self::tidy($ids[1])]);

        $job = $this->runAction('tidy-order-notes', $ids)->assertJsonPath('data.status', 'done')->json();
        self::assertIsArray($job);
        $message = (string) $job['data']['result']['message'];
        self::assertStringContainsString('for 1 record(s)', $message);
        self::assertStringContainsString('1 AI answer(s) were empty.', $message);

        $rows = iterator_to_array(CsvReader::fromString($this->download((int) $job['data']['id']))->rows(), false);
        self::assertSame([['id' => (string) $ids[1], 'notes' => self::tidy($ids[1])]], $rows);
        $row = $this->vectorQuery()->table(RewriteStore::TABLE)->where('job_id', '=', (int) $job['data']['id'])->where('record_key', '=', (string) $ids[0])->first();
        self::assertIsArray($row);
        self::assertSame([RewriteStore::FAILED, 'empty_answer', null], [$row['status'], $row['reason'], $row['proposal']]);
    }

    public function testProposalsNearingTheFileLimitStopTheCallsAcrossChunksAndSaySo(): void
    {
        $ids = $this->orders(4);
        $this->scriptTidy($ids);
        $jobId = $this->job('tidy-into-small-file');

        // one proposal, under the limit; the next chunk reads it back, proposes one more and passes it
        $this->chunk($jobId, 'tidy-into-small-file', [$ids[0]]);
        self::assertSame(ProposeRewrites::rowBytes((string) $ids[0], self::tidy($ids[0])), $this->rewrites()->bytes($jobId));
        $this->chunk($jobId, 'tidy-into-small-file', [$ids[1], $ids[2], $ids[3]]);
        self::assertCount(2, $this->calls());
        $finished = $this->finishJob($jobId, 'tidy-into-small-file');

        self::assertSame('download', $finished->type);
        $rows = iterator_to_array(CsvReader::fromString((string) $finished->contents)->rows(), false);
        self::assertSame([(string) $ids[0], (string) $ids[1]], array_column($rows, 'id'));
        self::assertStringContainsString("Stopped calling AI: the proposals filled the job's file; run the rest as another selection, so 2 record(s) were not sent.", (string) $finished->message);
    }

    public function testWritingSwitchedOffMidRunSendsNoMoreAndStillDeliversWhatWasMade(): void
    {
        $ids = $this->orders(3);
        $this->scriptTidy($ids);
        $jobId = $this->job('tidy-order-notes');

        $this->chunk($jobId, 'tidy-order-notes', [$ids[0]]);
        $this->settings(['writingEnabled' => false]);
        $response = $this->chunk($jobId, 'tidy-order-notes', [$ids[1], $ids[2]]);
        self::assertSame('message', $response->type);
        self::assertSame('Writing help is no longer available to this run: 2 record(s) in this chunk were not sent.', $response->message);
        self::assertCount(1, $this->calls());

        $finished = $this->finishJob($jobId, 'tidy-order-notes');
        self::assertSame('download', $finished->type);
        $rows = iterator_to_array(CsvReader::fromString((string) $finished->contents)->rows(), false);
        self::assertSame([['id' => (string) $ids[0], 'notes' => self::tidy($ids[0])]], $rows);
        self::assertStringContainsString('Stopped calling AI: writing help stopped being available to this run, so 2 record(s) were not sent.', (string) $finished->message);
    }

    public function testWritingSwitchedOffBetweenChunksOfOneScopeStopsTheNextChunk(): void
    {
        $ids = $this->orders(3);
        $this->scriptTidy($ids);
        $jobId = $this->job('tidy-order-notes');

        // as RunActionJob runs them: every chunk in the one scope of the job
        $second = $this->inJob(function (ActionRunner $runner, ResourceDescriptor $orders) use ($ids, $jobId): ActionResponse {
            $runner->runNow($orders, 'tidy-order-notes', [$ids[0]], [], $jobId);
            $this->settings(['writingEnabled' => false]);

            return $runner->runNow($orders, 'tidy-order-notes', [$ids[1], $ids[2]], [], $jobId);
        });

        self::assertSame('Writing help is no longer available to this run: 2 record(s) in this chunk were not sent.', $second->message);
        self::assertSame(0, $second->affected);
        self::assertCount(1, $this->calls());
        $left = $this->vectorQuery()->table(RewriteStore::TABLE)->select('record_key', 'reason')->where('job_id', '=', $jobId)
            ->where('status', '=', RewriteStore::BUDGET)->orderBy('record_key', 'ASC')->get();
        self::assertSame([(string) $ids[1] => 'unavailable', (string) $ids[2] => 'unavailable'], array_column($left, 'reason', 'record_key'));
    }

    public function testAChunkAuditsAndCountsOnlyTheRecordsItProposedFor(): void
    {
        $ids = $this->orders(4);
        $this->shop()->table('orders')->where('id', '=', $ids[2])->update(['notes' => self::LOCKED . self::note($ids[2])]);
        $this->scriptWriting(
            [self::note($ids[0]) => self::tidy($ids[0]), self::note($ids[3]) => self::tidy($ids[3])],
            [self::note($ids[1]) => new ProviderException('down')],
        );
        $jobId = $this->job('tidy-order-notes');

        [$first, $again] = $this->inJob(static fn (ActionRunner $runner, ResourceDescriptor $orders): array => [
            $runner->runNow($orders, 'tidy-order-notes', $ids, [], $jobId),
            // delivered again: every record is done, so none is proposed for, or audited, again
            $runner->runNow($orders, 'tidy-order-notes', $ids, [], $jobId),
        ]);

        self::assertSame(2, $first->affected, 'a proposal each for two of the four: one failed, one may not be updated');
        self::assertSame('Proposed 2 rewrite(s) in this chunk.', $first->message);
        self::assertSame(0, $again->affected);
        $audited = $this->vectorQuery()->table('vector_audit')->select('record_id')->where('resource', '=', 'orders')
            ->where('event', '=', 'action:tidy-order-notes')->where('actor_id', '=', self::ACTOR_PREFIX . '01')->orderBy('id', 'ASC')->get();
        self::assertSame([(string) $ids[0], (string) $ids[3]], array_column($audited, 'record_id'));
    }

    public function testTheCsvNeverPassesTheFileCapAndCountsWhatDidNotFit(): void
    {
        $ids = $this->orders(4);
        // quote-heavy: every quote is doubled in the CSV, so the file grows faster than the text
        $answers = [];

        foreach ($ids as $id) {
            $answers[self::note($id)] = self::quoted($id);
        }

        $this->scriptWriting($answers);
        $jobId = $this->job('tidy-into-tiny-csv');
        $this->chunk($jobId, 'tidy-into-tiny-csv', $ids);
        self::assertCount(4, $this->calls(), 'the early stop is far off: all four are proposed');
        $finished = $this->finishJob($jobId, 'tidy-into-tiny-csv');

        $csv = (string) $finished->contents;
        self::assertLessThanOrEqual(TidyIntoTinyCsv::CAP, strlen($csv));
        $rows = iterator_to_array(CsvReader::fromString($csv)->rows(), false);
        self::assertSame([(string) $ids[0], (string) $ids[1]], array_column($rows, 'id'), 'whole rows, in order, until the next would not fit');
        self::assertSame([self::quoted($ids[0]), self::quoted($ids[1])], array_column($rows, 'notes'));
        $message = (string) $finished->message;
        self::assertStringContainsString('for 2 record(s)', $message);
        self::assertStringContainsString("Left out 2 proposal(s) because the proposals filled the job's file; run the rest as another selection.", $message);
        // what did not fit is kept until the prune, as every row is
        self::assertSame(4, $this->vectorQuery()->table(RewriteStore::TABLE)->where('job_id', '=', $jobId)->where('status', '=', RewriteStore::PROPOSED)->count());
    }

    public function testTheCsvWriterStopsBeforeTheRowThatWouldPassItsLimit(): void
    {
        $header = ProposeRewrites::csv(['id', 'notes'], []);
        $row = strlen(ProposeRewrites::csv(['id', 'notes'], [['1', 'say "hi"']])) - strlen($header);

        [$csv, $fitted] = ProposeRewrites::csvWithin(['id', 'notes'], [['1', 'say "hi"'], ['2', 'say "hi"'], ['3', 'x']], strlen($header) + 2 * $row - 1);
        self::assertSame(1, $fitted);
        self::assertSame(strlen($header) + $row, strlen($csv));
        self::assertLessThanOrEqual(ProposeRewrites::rowBytes('1', 'say "hi"'), $row, 'rowBytes() never undercounts a row');
    }

    public function testARecordTheUserMayNotUpdateIsSkippedAndNeverSent(): void
    {
        $ids = $this->orders(3);
        $this->scriptTidy($ids);
        $this->shop()->table('orders')->where('id', '=', $ids[1])->update(['notes' => self::LOCKED . self::note($ids[1])]);
        $this->shop()->table('orders')->where('id', '=', $ids[2])->update(['notes' => '   ']);

        $job = $this->runAction('tidy-order-notes', $ids)->assertJsonPath('data.status', 'done')->json();
        self::assertIsArray($job);

        self::assertCount(1, $this->calls());

        foreach ($this->calls() as $call) {
            self::assertStringNotContainsString(self::LOCKED, self::sent($call));
        }

        $message = (string) $job['data']['result']['message'];
        self::assertStringContainsString('for 1 record(s)', $message);
        self::assertStringContainsString('Left out 1 that this account may not update.', $message);
        self::assertStringContainsString('Left out 1 that had no text.', $message);
        $rows = iterator_to_array(CsvReader::fromString($this->download((int) $job['data']['id']))->rows(), false);
        self::assertSame([(string) $ids[0]], array_column($rows, 'id'));
    }

    public function testASpentBudgetStopsTheCallsKeepsWhatWasProposedAndSaysSo(): void
    {
        $ids = $this->orders(4);
        $this->scriptTidy($ids);
        // one token left today: the first call is made, and it spends the rest
        $this->settings(['dailyTokenBudgetPerUser' => 1000]);
        $this->vectorQuery()->table('vector_ai_usage')->insert([
            'actor_id' => self::ACTOR_PREFIX . '01', 'impersonator_id' => null, 'feature' => 'writing', 'provider' => 'test-fake', 'model' => 'fake',
            'input_tokens' => 999, 'output_tokens' => 0, 'duration_ms' => 1, 'tool_calls' => 0, 'outcome' => 'answered',
            'created_at' => gmdate('Y-m-d H:i:s.000000'),
        ]);

        $job = $this->runAction('tidy-order-notes', $ids)->assertJsonPath('data.status', 'done')->json();
        self::assertIsArray($job);

        self::assertCount(1, $this->calls());
        $message = (string) $job['data']['result']['message'];
        self::assertStringContainsString("Stopped calling AI: today's budget ran out, so 3 record(s) were not sent.", $message);
        $rows = iterator_to_array(CsvReader::fromString($this->download((int) $job['data']['id']))->rows(), false);
        self::assertSame([['id' => (string) $ids[0], 'notes' => self::tidy($ids[0])]], $rows);
    }

    public function testTheBudgetIsCountedCallByCallWithinOneChunk(): void
    {
        $ids = $this->orders(8);
        $this->scriptTidy($ids);
        // 100 tokens left today: read once at the chunk's start, each call's tokens counted off it
        $left = 100;
        $this->settings(['dailyTokenBudgetPerUser' => 1000]);
        $this->vectorQuery()->table('vector_ai_usage')->insert([
            'actor_id' => self::ACTOR_PREFIX . '01', 'impersonator_id' => null, 'feature' => 'filters', 'provider' => 'test-fake', 'model' => 'fake',
            'input_tokens' => 1000 - $left, 'output_tokens' => 0, 'duration_ms' => 1, 'tool_calls' => 0, 'outcome' => 'answered',
            'created_at' => gmdate('Y-m-d H:i:s.000000'),
        ]);

        $job = $this->runAction('tidy-order-notes', $ids)->assertJsonPath('data.status', 'done')->json();
        self::assertIsArray($job);

        $spent = array_map(
            static fn (array $row): int => (int) $row['input_tokens'] + (int) $row['output_tokens'],
            $this->vectorQuery()->table('vector_ai_usage')->where('actor_id', '=', self::ACTOR_PREFIX . '01')->where('feature', '=', 'writing')->orderBy('id', 'ASC')->get(),
        );
        $calls = count($this->calls());
        self::assertCount($calls, $spent, 'one usage row a call');
        self::assertGreaterThan(1, $calls, 'several calls fit in what was left');
        self::assertLessThan(count($ids), $calls, 'and the rest of the same chunk was not sent');
        self::assertLessThan($left, array_sum(array_slice($spent, 0, -1)), 'each call was made while tokens were left');
        self::assertGreaterThanOrEqual($left, array_sum($spent), 'and none once they were spent');
        $unsent = count($ids) - $calls;
        self::assertStringContainsString("Stopped calling AI: today's budget ran out, so {$unsent} record(s) were not sent.", (string) $job['data']['result']['message']);
    }

    public function testAnAnswerOverTheCapIsCut(): void
    {
        $ids = $this->orders(1);
        $this->shop()->table('orders')->where('id', '=', $ids[0])->update(['notes' => 'Call first.']);
        // no MaxLength rule on notes: the cap is four times the input's 11 characters
        $this->scriptWriting(['Call first.' => str_repeat('Please call the customer first. ', 10)]);

        $job = $this->runAction('tidy-order-notes', $ids)->assertJsonPath('data.status', 'done')->json();
        self::assertIsArray($job);

        $rows = iterator_to_array(CsvReader::fromString($this->download((int) $job['data']['id']))->rows(), false);
        self::assertSame(mb_substr(str_repeat('Please call the customer first. ', 10), 0, 44), $rows[0]['notes']);
        self::assertStringContainsString("1 answer(s) were cut at the field's length limit.", (string) $job['data']['result']['message']);
    }

    public function testAProposalEndingInABackslashIsLeftOutCountedAndTheOthersSurviveTheImport(): void
    {
        $ids = $this->orders(3);
        // C:\dir\ — the importer would read the closing quote as escaped and swallow the next rows
        $this->scriptWriting([self::note($ids[0]) => self::tidy($ids[0]), self::note($ids[1]) => 'Saved under C:\\dir\\', self::note($ids[2]) => self::tidy($ids[2])]);

        $job = $this->runAction('tidy-order-notes', $ids)->assertJsonPath('data.status', 'done')->json();
        self::assertIsArray($job);
        self::assertCount(3, $this->calls());
        $message = (string) $job['data']['result']['message'];
        self::assertStringContainsString('for 2 record(s)', $message);
        self::assertStringContainsString('Left out 1 that ended in a backslash, which the CSV cannot carry back to the import.', $message);

        $csv = $this->download((int) $job['data']['id']);
        $rows = iterator_to_array(CsvReader::fromString($csv)->rows(), false);
        self::assertSame([
            ['id' => (string) $ids[0], 'notes' => self::tidy($ids[0])],
            ['id' => (string) $ids[2], 'notes' => self::tidy($ids[2])],
        ], $rows);

        $import = $this->import($csv);
        self::assertSame(['created' => 0, 'failed' => 0, 'updated' => 2], self::sorted($import['data']['result']));
        self::assertSame([$ids[0] => self::tidy($ids[0]), $ids[1] => self::note($ids[1]), $ids[2] => self::tidy($ids[2])], $this->notesNow($ids));
    }

    public function testTheCsvWriterRefusesAValueEndingInABackslash(): void
    {
        self::assertFalse(ProposeRewrites::encodable('C:\\dir\\'));
        self::assertTrue(ProposeRewrites::encodable('a back\\slash in the middle'));
        $this->expectException(\InvalidArgumentException::class);
        ProposeRewrites::csv(['id', 'notes'], [['1', 'ends with a backslash\\'], ['2', 'next']]);
    }

    public function testCommasQuotesAndLineBreaksSurviveTheImportRoundTrip(): void
    {
        $ids = $this->orders(2);
        $tricky = [
            "Ring twice, then \"knock\";\nleave it, if nobody answers,\nat the back door.",
            "=SUM(A1) is not a formula here; a back\\slash \\\"quoted\\\" and 'single' stay too,\r\nas does a trailing comma,",
        ];
        $expected = [$tricky[0], str_replace("\r\n", "\n", $tricky[1])];
        $this->scriptWriting([self::note($ids[0]) => $tricky[0], self::note($ids[1]) => $tricky[1]]);

        $job = $this->runAction('tidy-order-notes', $ids)->assertJsonPath('data.status', 'done')->json();
        self::assertIsArray($job);
        $csv = $this->download((int) $job['data']['id']);
        self::assertSame($expected, array_column(iterator_to_array(CsvReader::fromString($csv)->rows(), false), 'notes'), 'the CSV holds the text exactly');

        $import = $this->import($csv);
        self::assertSame(['created' => 0, 'failed' => 0, 'updated' => 2], self::sorted($import['data']['result']));
        self::assertSame(array_combine($ids, $expected), $this->notesNow($ids));
    }

    /**
     * Gives $count orders known notes (self::note()), keeping theirs to put back.
     *
     * @return list<int>
     */
    private function orders(int $count): array
    {
        $rows = $this->shop()->table('orders')->select('id', 'notes')->orderBy('id', 'ASC')->limit($count)->get();
        self::assertCount($count, $rows, "the demo seeds at least {$count} orders");
        $ids = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $this->notes[$id] ??= $row['notes'] === null ? null : (string) $row['notes'];
            $this->shop()->table('orders')->where('id', '=', $id)->update(['notes' => self::note($id)]);
            $ids[] = $id;
        }

        return $ids;
    }

    /** A queued action job of the tests' first actor, as RunActionJob would run in. */
    private function job(string $action): int
    {
        return $this->jobStore()->create('action', 'orders', $action, self::ACTOR_PREFIX . '01');
    }

    /**
     * One chunk of job $jobId, as RunActionJob runs it, in a request scope of its own (so it reads the settings anew).
     *
     * @param list<int> $ids
     */
    private function chunk(int $jobId, string $action, array $ids): ActionResponse
    {
        return $this->inJob(static fn (ActionRunner $runner, ResourceDescriptor $orders): ActionResponse => $runner->runNow($orders, $action, $ids, [], $jobId));
    }

    private function finishJob(int $jobId, string $action): ActionResponse
    {
        $finished = $this->inJob(static fn (ActionRunner $runner, ResourceDescriptor $orders): ?ActionResponse => $runner->finish($orders, $action, [], $jobId));
        self::assertNotNull($finished);

        return $finished;
    }

    /**
     * @template T
     * @param \Closure(ActionRunner, ResourceDescriptor, JobContext): T $run
     * @return T
     */
    private function inJob(\Closure $run): mixed
    {
        $scope = $this->app->createRequestScope();

        try {
            $context = $scope->get(JobContext::class);
            self::assertInstanceOf(JobContext::class, $context);
            $context->actAs(self::ACTOR_PREFIX . '01');
            $runner = $context->get(ActionRunner::class);
            $service = $context->get(ResourceService::class);
            self::assertInstanceOf(ActionRunner::class, $runner);
            self::assertInstanceOf(ResourceService::class, $service);

            return $run($runner, $service->visible($service->resource('orders')), $context);
        } finally {
            $scope->dispose();
        }
    }

    private function rewrites(): RewriteStore
    {
        return new RewriteStore($this->vectorDatabase());
    }

    private function jobStore(): JobStore
    {
        return new JobStore($this->vectorDatabase());
    }

    private function vectorDatabase(): VectorDatabase
    {
        $database = $this->app->get(VectorDatabase::class);
        self::assertInstanceOf(VectorDatabase::class, $database);

        return $database;
    }

    private static function note(int $id): string
    {
        return "order {$id}: costumer want's delivery, after 5pm \"please\"";
    }

    /** A quote-heavy answer for order $id: 48 bytes, of them 12 quotes, that the CSV writes as 62. */
    private static function quoted(int $id): string
    {
        return 'Say ' . str_repeat('"yes" ', 6) . sprintf('to %04d.', $id % 10000);
    }

    private static function tidy(int $id): string
    {
        return "Order {$id}: the customer wants delivery after 5 pm, \"please\".";
    }

    /** @param list<int> $ids */
    private function scriptTidy(array $ids): void
    {
        $answers = [];

        foreach ($ids as $id) {
            $answers[self::note($id)] = self::tidy($id);
        }

        $this->scriptWriting($answers);
    }

    /**
     * Runs $action on $ids of $resource as actor $actor through the sync queue; the job as it ended.
     *
     * @param list<int> $ids
     */
    private function runAction(string $action, array $ids, string $resource = 'orders', int $actor = 1): TestResponse
    {
        // the sync queue runs the job in this request: a job that failed fails it too
        $this->client->post("/vector/r/{$resource}/actions/{$action}", body: ['ids' => $ids], headers: $this->aiHeaders($actor));
        $jobId = $this->vectorQuery()->table('vector_jobs')->where('name', '=', $action)
            ->where('actor_id', '=', self::ACTOR_PREFIX . str_pad((string) $actor, 2, '0', STR_PAD_LEFT))->orderBy('id', 'DESC')->value('id');
        self::assertNotNull($jobId, "{$action} was queued");

        return $this->client->get("/vector/jobs/{$jobId}", headers: $this->aiHeaders($actor))->assertOk();
    }

    private function download(int $jobId): string
    {
        return $this->client->get("/vector/jobs/{$jobId}/download", headers: $this->aiHeaders())->assertOk()->body();
    }

    /** @return array<string, mixed> the import job of $csv, update mode keyed by id, as it ended */
    private function import(string $csv): array
    {
        $body = '';

        foreach (['mapping' => '{"id":"id","notes":"notes"}', 'mode' => 'update', 'key' => 'id'] as $name => $value) {
            $body .= "--vector\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }

        $body .= "--vector\r\nContent-Disposition: form-data; name=\"file\"; filename=\"proposals.csv\"\r\nContent-Type: text/csv\r\n\r\n{$csv}\r\n--vector--\r\n";
        $queued = $this->client->raw('POST', '/vector/r/orders/import', $body, ['Content-Type' => 'multipart/form-data; boundary=vector', ...$this->aiHeaders()])
            ->assertStatus(202)->json();
        self::assertIsArray($queued);
        $job = $this->client->get("/vector/jobs/{$queued['jobId']}", headers: $this->aiHeaders())->assertOk()->assertJsonPath('data.status', 'done')->json();
        self::assertIsArray($job);

        return $job;
    }

    /**
     * @param list<int> $ids
     * @return array<int, ?string> id => notes now
     */
    private function notesNow(array $ids): array
    {
        $rows = $this->shop()->table('orders')->select('id', 'notes')->whereIn('id', $ids)->orderBy('id', 'ASC')->get();
        $notes = [];

        foreach ($rows as $row) {
            $notes[(int) $row['id']] = $row['notes'] === null ? null : (string) $row['notes'];
        }

        return $notes;
    }

    /**
     * @param mixed $result
     * @return array<string, mixed>
     */
    private static function sorted(mixed $result): array
    {
        self::assertIsArray($result);
        ksort($result);

        return $result;
    }
}

/** The demo's actors, and the tests' own (`9902<n>`), as admins. */
final readonly class TestActors implements ActorResolverInterface
{
    public function __construct(private ActorResolverInterface $demo) {}

    #[\Override]
    public function resolve(string $actorId): ?CurrentUserInterface
    {
        return str_starts_with($actorId, '9902') ? new DemoActor((int) $actorId, 'Test', true) : $this->demo->resolve($actorId);
    }
}

/** Everything allowed, except updating an order whose notes are LOCKED. */
final class LockedNotesPolicy extends ResourcePolicy
{
    #[\Override]
    public function update(CurrentUserInterface $user, array $record): bool
    {
        return !is_string($record['notes'] ?? null) || !str_starts_with($record['notes'], 'LOCKED ');
    }
}

/** Every AI feature to everyone, but writing to one actor. */
final readonly class NoWritingFor implements AiAccessInterface
{
    public function __construct(private string $actorId) {}

    #[\Override]
    public function canUse(CurrentUserInterface $user, Feature $feature): bool
    {
        return $feature !== Feature::Writing || (string) $user->id() !== $this->actorId;
    }

    #[\Override]
    public function canConfigure(CurrentUserInterface $user): bool
    {
        return true;
    }
}

final class RewriteOrderStatus extends ProposeRewrites
{
    #[\Override]
    public function field(): string
    {
        return 'status';
    }

    #[\Override]
    public function operation(): WritingOperation
    {
        return WritingOperation::Rewrite;
    }
}

final class RewritePasswords extends ProposeRewrites
{
    #[\Override]
    public function field(): string
    {
        return 'passwordHash';
    }

    #[\Override]
    public function operation(): WritingOperation
    {
        return WritingOperation::Rewrite;
    }
}

final class RewriteTagNames extends ProposeRewrites
{
    #[\Override]
    public function field(): string
    {
        return 'name';
    }

    #[\Override]
    public function operation(): WritingOperation
    {
        return WritingOperation::Rewrite;
    }
}

final class RewriteProfileNames extends ProposeRewrites
{
    #[\Override]
    public function field(): string
    {
        return 'name';
    }

    #[\Override]
    public function operation(): WritingOperation
    {
        return WritingOperation::Rewrite;
    }
}

final class RewriteProductDescriptions extends ProposeRewrites
{
    #[\Override]
    public function field(): string
    {
        return 'description';
    }

    #[\Override]
    public function operation(): WritingOperation
    {
        return WritingOperation::Rewrite;
    }
}

/** The demo's TidyOrderNotes with a file of 100 bytes of proposals: a little over one. */
final class TidyIntoSmallFile extends ProposeRewrites
{
    #[\Override]
    public function field(): string
    {
        return 'notes';
    }

    #[\Override]
    public function operation(): WritingOperation
    {
        return WritingOperation::Grammar;
    }

    #[\Override]
    protected function fileLimit(): int
    {
        return 100;
    }
}

/**
 * The demo's TidyOrderNotes with a CSV of at most CAP bytes: the BOM and
 * header (13), then two quote-heavy rows of ProposeRewritesTest::quoted()
 * (about 67 each) fit, and a third does not.
 */
final class TidyIntoTinyCsv extends ProposeRewrites
{
    public const int CAP = 180;

    #[\Override]
    public function field(): string
    {
        return 'notes';
    }

    #[\Override]
    public function operation(): WritingOperation
    {
        return WritingOperation::Grammar;
    }

    #[\Override]
    protected function fileCap(): int
    {
        return self::CAP;
    }
}

final class LongGuidance extends ProposeRewrites
{
    #[\Override]
    public function field(): string
    {
        return 'notes';
    }

    #[\Override]
    public function operation(): WritingOperation
    {
        return WritingOperation::Rewrite;
    }

    #[\Override]
    public function instructions(): string
    {
        return str_repeat('a', WritingAssistant::MAX_INSTRUCTIONS + 1);
    }
}

final class TranslateWithoutLocale extends ProposeRewrites
{
    #[\Override]
    public function field(): string
    {
        return 'notes';
    }

    #[\Override]
    public function operation(): WritingOperation
    {
        return WritingOperation::Translate;
    }
}
