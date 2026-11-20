<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;

/**
 * A forced delete is proposed when the policy's forceDelete allows it,
 * asked of the record itself as Vector's own forced delete asks it: of a
 * live record as of one in the trash — not the trash button's
 * recordPermissions()['forceDelete'], which is false for a live record.
 * Delete itself is refused here, so only forceDelete can let it through.
 * ProposalPermissionsTest covers the other side: delete alone does not.
 */
final class ForcedDeleteProposalTest extends ApprovalsTestCase
{
    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(AllowAllPolicy::class, new ForceDeleteOnlyPolicy());
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->shop()->table('products')->where('id', '=', $this->product)->update(['deleted_at' => null]);
        parent::tearDown();
    }

    public function testAForcedDeleteOfALiveRecordIsProposedWhenForceDeleteAllowsIt(): void
    {
        $approval = $this->proposed(['resource' => 'products', 'id' => (string) $this->product, 'force' => true], 'vector_delete');

        self::assertSame('vector_delete', $approval['tool']);
        self::assertSame('pending', $this->row($approval['id'])['status'] ?? null);
    }

    public function testAForcedDeleteOfATrashedRecordIsProposedWhenForceDeleteAllowsIt(): void
    {
        $this->shop()->table('products')->where('id', '=', $this->product)->update(['deleted_at' => '2026-01-01 00:00:00']);

        $approval = $this->proposed(['resource' => 'products', 'id' => (string) $this->product, 'force' => true], 'vector_delete');

        self::assertSame('pending', $this->row($approval['id'])['status'] ?? null);
    }

    public function testAPlainDeleteIsStillRefusedWithoutDelete(): void
    {
        $run = $this->propose(['resource' => 'products', 'id' => (string) $this->product], 'vector_delete');

        self::assertSame([], $run['approvals']);
        self::assertSame([], $this->rows());
    }
}

/** Lets everyone read and force-delete, and nobody delete. */
final class ForceDeleteOnlyPolicy extends ResourcePolicy
{
    #[\Override]
    public function delete(CurrentUserInterface $user, array $record): bool
    {
        return false;
    }

    #[\Override]
    public function forceDelete(CurrentUserInterface $user, array $record): bool
    {
        return true; // ResourcePolicy's own asks delete()
    }
}
