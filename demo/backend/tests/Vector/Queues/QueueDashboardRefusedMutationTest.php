<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\Queues\Fixtures\FixedPolicy;

/**
 * A viewer the policy lets look but not change anything: every mutation
 * is a 403 before the store, the retrier or the queue is built, and the
 * permissions the UI reads come from the policy alone.
 */
final class QueueDashboardRefusedMutationTest extends RefusedDashboardTestCase
{
    protected function policy(): FixedPolicy
    {
        return new FixedPolicy(view: true, mutate: false);
    }

    public function testEveryMutationIsA403BeforeAnythingIsBuilt(): void
    {
        $this->assertEveryMutationIsRefused();
    }

    public function testThePermissionsComeFromThePolicyWithoutAnyRead(): void
    {
        $response = $this->client->get('/vector/queues/permissions', headers: $this->adminHeaders())->assertOk();

        self::assertSame(
            '{"view":true,"retry":false,"forget":false,"prune":false,"clear":{"qa-mail":false,"qa-reports":false,"qa-idle":false}}',
            $response->body(),
        );
        $this->assertNothingWasBuilt();
    }
}
