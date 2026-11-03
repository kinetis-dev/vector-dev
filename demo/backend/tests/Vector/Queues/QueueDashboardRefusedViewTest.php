<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\Queues\Fixtures\FixedPolicy;

/**
 * A caller Vector admits but the policy may not even let look: for them
 * the dashboard is not there (`capabilities.queues` is false), so every
 * route — a read or a mutation, whatever the policy says of it — is the
 * Kernel's own 404, and nothing behind it is built.
 */
final class QueueDashboardRefusedViewTest extends RefusedDashboardTestCase
{
    protected function policy(): FixedPolicy
    {
        return new FixedPolicy(view: false, mutate: false);
    }

    public function testEveryReadIsA404BeforeAnythingIsBuilt(): void
    {
        $headers = $this->adminHeaders();

        $this->client->get('/vector/queues', headers: $headers)->assertStatus(404);
        $this->client->get('/vector/queues/failed', headers: $headers)->assertStatus(404);
        $this->client->get('/vector/queues/failed', ['queue' => 'qa-mail', 'limit' => '5'], $headers)->assertStatus(404);
        $this->client->get('/vector/queues/failed/' . self::ID, headers: $headers)->assertStatus(404);
        $this->client->get('/vector/queues/permissions', headers: $headers)->assertStatus(404);
        $this->client->get('/vector/queues/metrics', headers: $headers)->assertStatus(404);

        $this->assertNothingWasBuilt();
    }

    public function testEveryMutationIsA404BeforeAnythingIsBuilt(): void
    {
        $this->assertEveryMutationIsRefused(404);
    }

    public function testTheSchemaSaysTheDashboardIsNotThere(): void
    {
        $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()
            ->assertJsonPath('capabilities.queues', false)
            ->assertJsonPath('capabilities.failedJobs', false)
            ->assertJsonPath('capabilities.queueMetrics', false);
        $this->assertNothingWasBuilt();
    }
}
