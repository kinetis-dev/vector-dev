<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Jobs\JobContext;
use Kinetis\Vector\Store\JobStore;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * A job's notification is sent after its row is finished: when sending
 * it fails, the finished row stays done and the failure goes to the log.
 */
final class JobNotificationFailureTest extends VectorTestCase
{
    private RecordingLogger $log;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->log = new RecordingLogger();
        $app->instance(LoggerInterface::class, $this->log);
    }

    public function testAFailedNotificationLeavesTheFinishedJobDone(): void
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);
        $admin = (new Query($link))->table('users')->select('id')->where('email', '=', 'admin@vector.local')->value('id');
        $jobs = $this->app->get(JobStore::class);
        self::assertInstanceOf(JobStore::class, $jobs);
        $jobId = $jobs->create('action', 'products', 'notify-test', (string) $admin);
        $scope = $this->app->createRequestScope();

        try {
            self::assertInstanceOf(RequestScope::class, $scope);
            $context = $scope->get(JobContext::class);
            self::assertInstanceOf(JobContext::class, $context);

            // a notice that is not UTF-8: both engines refuse to store it, after the row was finished
            $context->run($jobId, (string) $admin, 'Notify test', static fn (): array => ['result' => ['ok' => true], 'notice' => "Done \xFF."]);
        } finally {
            $scope->dispose();
        }

        $job = $jobs->find($jobId);
        self::assertIsArray($job);
        self::assertSame(JobStore::DONE, $job['status']);
        self::assertNull($job['failure']);
        self::assertSame(['ok' => true], $job['result']);
        self::assertCount(1, $this->log->errors);
        self::assertStringContainsString('notification', $this->log->errors[0]);
    }
}

/** Keeps the error lines it is given. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $errors = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($level === 'error') {
            $this->errors[] = (string) $message;
        }
    }
}
