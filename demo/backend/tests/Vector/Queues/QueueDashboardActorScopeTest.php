<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\Queues\Fixtures\SendInvoiceJob;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Vector\Auth\ActorContext;
use Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface;

/**
 * The controller is built per request, with that request's ActorContext:
 * two callers Vector both admits, in turn on one application, are each
 * judged — and audited — as themselves. The policy tells them apart by
 * actor id alone, so a stale actor would answer both alike.
 */
final class QueueDashboardActorScopeTest extends QueuesTestCase
{
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(QueueDashboardPolicyInterface::class, new class implements QueueDashboardPolicyInterface {
            public function view(ActorContext $actor): bool
            {
                return true;
            }

            public function retry(ActorContext $actor): bool
            {
                return $actor->actorId() === '1';
            }

            public function forget(ActorContext $actor): bool
            {
                return $actor->actorId() === '1';
            }

            public function prune(ActorContext $actor): bool
            {
                return $actor->actorId() === '1';
            }

            public function clear(ActorContext $actor, string $queue): bool
            {
                return $actor->actorId() === '1';
            }
        });
    }

    public function testAnAllowedCallerThenARefusedOneOnTheSameApplication(): void
    {
        $this->queue->push(new SendInvoiceJob(1, 'x'), queue: 'qa-mail');
        $this->queue->push(new SendInvoiceJob(2, 'x'), queue: 'qa-reports');
        $other = ['Authorization' => 'Bearer ' . $this->issue('990002', true)];

        $this->client->post('/vector/queues/qa-mail/clear', ['confirm' => 'qa-mail'], $this->adminHeaders())->assertStatus(200);
        $this->client->post('/vector/queues/qa-reports/clear', ['confirm' => 'qa-reports'], $other)
            ->assertStatus(403)
            ->assertJsonPath('error', 'This account may not do that on the queue dashboard.');

        self::assertSame(0, $this->queue->size('qa-mail'));
        self::assertSame(1, $this->queue->size('qa-reports'));
        self::assertSame('1', $this->auditFor('qa-mail')[0]['actor_id'] ?? null);
        self::assertSame([], $this->auditFor('qa-reports'));
    }
}
