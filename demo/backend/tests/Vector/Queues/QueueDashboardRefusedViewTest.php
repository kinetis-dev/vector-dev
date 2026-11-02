<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\Queues\Fixtures\FixedPolicy;

/** A caller Vector admits but the policy may not even let look: every route is a 403, and nothing behind it is built. */
final class QueueDashboardRefusedViewTest extends RefusedDashboardTestCase
{
    protected function policy(): FixedPolicy
    {
        return new FixedPolicy(view: false, mutate: false);
    }

    public function testEveryReadIsA403BeforeAnythingIsBuilt(): void
    {
        $headers = $this->adminHeaders();

        $this->client->get('/vector/queues', headers: $headers)->assertStatus(403);
        $this->client->get('/vector/queues/failed', headers: $headers)->assertStatus(403);
        $this->client->get('/vector/queues/failed', ['queue' => 'qa-mail', 'limit' => '5'], $headers)->assertStatus(403);
        $this->client->get('/vector/queues/failed/' . self::ID, headers: $headers)->assertStatus(403);
        $this->client->get('/vector/queues/permissions', headers: $headers)->assertStatus(403);

        $this->assertNothingWasBuilt();
    }

    public function testEveryMutationIsA403BeforeAnythingIsBuilt(): void
    {
        $this->assertEveryMutationIsRefused();
    }
}
