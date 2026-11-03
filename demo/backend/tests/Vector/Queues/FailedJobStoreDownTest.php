<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\Queues\Fixtures\ClearableRecordingQueue;
use App\Tests\Vector\Queues\Fixtures\RecordingLink;
use App\Tests\Vector\VectorTestCase;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Queue\QueueInterface;
use Kinetis\QueueFailedJobs\SqlFailedJobStore;
use Kinetis\Vector\Queues\Http\QueuesController;

/** A failed-job store whose database is down: a retry stops the batch with a 500, and the id it stopped on is audited with status `error`. */
final class FailedJobStoreDownTest extends VectorTestCase
{
    private RecordingLink $down;

    /** @return array<string, string> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'VECTOR_QUEUES' => 'qa-mail'];
    }

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        $this->down = new RecordingLink();
        $app->instance(QueueInterface::class, new ClearableRecordingQueue());
        $app->instance(SqlFailedJobStore::class, new SqlFailedJobStore($this->down));
    }

    public function testARetryTheStoreFailsOnIsAuditedAsAnErrorBeforeThe500(): void
    {
        $before = (int) $this->vectorQuery()->table('vector_audit')->max('id');
        $id = bin2hex(random_bytes(16));
        $untouched = bin2hex(random_bytes(16));

        $this->client->post('/vector/queues/failed/retry', ['ids' => [$id, $untouched]], $this->adminHeaders())->assertStatus(500);

        self::assertNotSame([], $this->down->statements, 'the store was asked');
        $entries = $this->vectorQuery()->table('vector_audit')
            ->where('resource', '=', QueuesController::AUDIT_RESOURCE)
            ->where('id', '>', $before)
            ->get();
        self::assertCount(1, $entries, 'the batch stopped at the first id');
        self::assertSame($id, $entries[0]['record_id']);
        self::assertSame('queue:retry', $entries[0]['event']);
        self::assertStringContainsString('"status":"error"', (string) $entries[0]['changes']);
    }
}
