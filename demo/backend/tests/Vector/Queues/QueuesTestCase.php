<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\Queues\Fixtures\ClearableRecordingQueue;
use App\Tests\Vector\VectorTestCase;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Queue\FailedJob;
use Kinetis\Queue\QueueInterface;
use Kinetis\QueueFailedJobs\SqlFailedJobStore;

/**
 * The queue dashboard over the demo's real failed-jobs table, on queue
 * names of the tests' own (VECTOR_QUEUES), so a failure the demo's
 * worker recorded is never touched. Every record a test made is
 * forgotten before and after it.
 */
abstract class QueuesTestCase extends VectorTestCase
{
    protected const array QUEUES = ['qa-mail', 'qa-reports', 'qa-idle'];

    protected ClearableRecordingQueue $queue;

    /** The newest audit row before the test began: auditFor() reads only what the test itself wrote. */
    private int $auditBefore = 0;

    /** @return array<string, string> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'VECTOR_QUEUES' => implode(',', self::QUEUES)];
    }

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        $this->queue = new ClearableRecordingQueue();
        $app->instance(QueueInterface::class, $this->queue);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetTestRecords();
        $this->auditBefore = (int) $this->vectorQuery()->table('vector_audit')->max('id');
    }

    protected function tearDown(): void
    {
        $this->forgetTestRecords();
        parent::tearDown();
    }

    protected function store(): SqlFailedJobStore
    {
        $store = $this->app->get(SqlFailedJobStore::class);
        self::assertInstanceOf(SqlFailedJobStore::class, $store);

        return $store;
    }

    /**
     * @param string $class a job class — or the name of one that no longer exists
     * @param array<string, mixed> $args
     */
    protected function recordFailure(string $queue, int $failedAt, string $class = Fixtures\SendInvoiceJob::class, array $args = ['invoiceId' => 7, 'apiToken' => 'sk_live_TOP_SECRET']): string
    {
        /** @var class-string<\Kinetis\Queue\Job> $jobClass */
        $jobClass = $class;

        return $this->store()->record(new FailedJob(null, $queue, $jobClass, $args, 3, 3, \RuntimeException::class, 'The mail server refused the message.', $failedAt));
    }

    private function forgetTestRecords(): void
    {
        foreach ([...self::QUEUES, 'qa-elsewhere'] as $queue) {
            do {
                $page = $this->store()->list(100, false, $queue);

                foreach ($page as $stored) {
                    $this->store()->forget($stored->id);
                }
            } while ($page !== []);
        }
    }

    /** @return list<array<string, mixed>> the dashboard's audit entries for one record id this test wrote, newest first */
    protected function auditFor(string $recordId): array
    {
        return $this->vectorQuery()->table('vector_audit')
            ->where('resource', '=', 'vector-queues')
            ->where('record_id', '=', $recordId)
            ->where('id', '>', $this->auditBefore)
            ->orderBy('id', 'DESC')
            ->get();
    }
}
