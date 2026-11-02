<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

/** Without VECTOR_QUEUES the dashboard watches `default` alone: a worker's --queue list is not discoverable over HTTP. */
final class QueueNamesDefaultTest extends QueuesTestCase
{
    /** @return array<string, string> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'VECTOR_QUEUES' => ''];
    }

    public function testTheDefaultQueueIsTheOnlyOne(): void
    {
        $page = $this->client->get('/vector/queues', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($page);
        self::assertSame(['default'], array_column($page['data'], 'name'));
        $this->client->get('/vector/queues/failed', ['queue' => 'qa-mail'], $this->adminHeaders())->assertValidationError('queue');
    }
}
