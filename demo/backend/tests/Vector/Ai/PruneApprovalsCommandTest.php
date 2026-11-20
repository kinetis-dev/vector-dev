<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Tests\Fixtures\CommandOutput;
use Kinetis\Console\CommandArguments;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\VectorAi\Approvals\Approval;
use Kinetis\VectorAi\Approvals\ApprovalId;
use Kinetis\VectorAi\Approvals\ApprovalStore;
use Kinetis\VectorAi\Console\PruneApprovalsCommand;

/**
 * vector-ai:approvals:prune marks stale pending proposals expired, fails
 * those whose execution never finished with the unknown outcome, and
 * deletes those decided more than --days ago — and leaves a fresh
 * pending one, a recently decided one and one executing for less than
 * ApprovalStore::EXECUTING_MINUTES alone.
 */
final class PruneApprovalsCommandTest extends AiTestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        $this->vectorQuery()->table(ApprovalStore::TABLE)->where('actor_id', 'LIKE', self::ACTOR_PREFIX . '%')->delete();
        parent::tearDown();
    }

    public function testItExpiresStaleProposalsAndDeletesOldDecidedOnes(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rows = [
            'fresh' => $this->insert('pending', $now->modify('-1 minute'), $now->modify('+14 minutes'), null),
            'stale' => $this->insert('pending', $now->modify('-1 hour'), $now->modify('-45 minutes'), null),
            'recent' => $this->insert('executed', $now->modify('-2 days'), $now->modify('-2 days'), $now->modify('-2 days')),
            'old-executed' => $this->insert('executed', $now->modify('-40 days'), $now->modify('-40 days'), $now->modify('-40 days')),
            'old-rejected' => $this->insert('rejected', $now->modify('-31 days'), $now->modify('-31 days'), $now->modify('-31 days')),
            'old-failed' => $this->insert('failed', $now->modify('-60 days'), $now->modify('-60 days'), $now->modify('-60 days')),
            'executing' => $this->insert('executing', $now->modify('-2 minutes'), $now->modify('+13 minutes'), $now->modify('-1 minute')),
            'unfinished' => $this->insert('executing', $now->modify('-20 minutes'), $now->modify('-5 minutes'), $now->modify('-11 minutes')),
        ];

        [$status, $stdout, $stderr] = $this->prune([]);

        self::assertSame(0, $status);
        self::assertSame('', $stderr);
        self::assertSame("Expired 1 pending approval(s); failed 1 unfinished approval(s); deleted 3 decided approval(s) older than 30 day(s).\n", $stdout);
        self::assertSame(['fresh' => 'pending', 'stale' => 'expired', 'recent' => 'executed', 'executing' => 'executing', 'unfinished' => 'failed'], $this->statuses($rows));
        $result = $this->vectorQuery()->table(ApprovalStore::TABLE)->where('id', '=', $rows['unfinished'])->value('result');
        self::assertSame(['error' => Approval::UNKNOWN_OUTCOME], json_decode((string) $result, true, 4, JSON_THROW_ON_ERROR));

        // a shorter window takes the recent one too; the one it just expired was decided now
        [$status, $stdout] = $this->prune(['--days=1']);
        self::assertSame(0, $status);
        self::assertSame("Expired 0 pending approval(s); failed 0 unfinished approval(s); deleted 1 decided approval(s) older than 1 day(s).\n", $stdout);
        self::assertSame(['fresh' => 'pending', 'stale' => 'expired', 'executing' => 'executing', 'unfinished' => 'failed'], $this->statuses($rows));
    }

    public function testFailStaleFailsOnlyExecutingRowsApprovedBeforeTheBound(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rows = [
            'old' => $this->insert('executing', $now->modify('-1 hour'), $now->modify('-45 minutes'), $now->modify('-30 minutes')),
            'recent' => $this->insert('executing', $now->modify('-2 minutes'), $now->modify('+13 minutes'), $now->modify('-1 minute')),
            'pending' => $this->insert('pending', $now->modify('-1 hour'), $now->modify('-45 minutes'), null),
            'executed' => $this->insert('executed', $now->modify('-1 hour'), $now->modify('-45 minutes'), $now->modify('-30 minutes')),
        ];

        self::assertSame(1, $this->store()->failStale(ApprovalStore::staleBefore()));
        self::assertSame(['old' => 'failed', 'recent' => 'executing', 'pending' => 'pending', 'executed' => 'executed'], $this->statuses($rows));
        $result = $this->vectorQuery()->table(ApprovalStore::TABLE)->where('id', '=', $rows['old'])->value('result');
        self::assertSame(['error' => Approval::UNKNOWN_OUTCOME], json_decode((string) $result, true, 4, JSON_THROW_ON_ERROR));
        self::assertSame(0, $this->store()->failStale(ApprovalStore::staleBefore()), 'a failed row is not failed again');
    }

    public function testARefusedDaysWritesNothing(): void
    {
        [$status, $stdout, $stderr] = $this->prune(['--days=0']);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertSame("--days needs a whole number of days from 1 to 36500, e.g. --days=30.\n", $stderr);
    }

    /**
     * @param list<string> $argv
     * @return array{int, string, string}
     */
    private function prune(array $argv): array
    {
        $capture = new CommandOutput();
        $status = new PruneApprovalsCommand($this->store(), $capture->output)->run(CommandArguments::parse($argv));

        return [$status, $capture->stdout(), $capture->stderr()];
    }

    private function store(): ApprovalStore
    {
        $database = $this->app->get(VectorDatabase::class);
        self::assertInstanceOf(VectorDatabase::class, $database);

        return new ApprovalStore($database);
    }

    private function insert(string $status, \DateTimeImmutable $created, \DateTimeImmutable $expires, ?\DateTimeImmutable $decided): string
    {
        $id = ApprovalId::generate();
        $format = static fn (?\DateTimeImmutable $at): ?string => $at?->format('Y-m-d H:i:s.u');
        $this->vectorQuery()->table(ApprovalStore::TABLE)->insert([
            'id' => $id,
            'actor_id' => self::ACTOR_PREFIX . '01',
            'impersonator_id' => null,
            'conversation_id' => 'c_prune',
            'tool' => 'vector_delete',
            'arguments' => '{"resource":"products","id":"1"}',
            'arguments_hash' => hash('sha256', '{"resource":"products","id":"1"}'),
            'status' => $status,
            'created_at' => $format($created),
            'expires_at' => $format($expires),
            'decided_at' => $format($decided),
        ]);

        return $id;
    }

    /**
     * @param array<string, string> $rows name => id
     * @return array<string, string> name => status, for the rows still there
     */
    private function statuses(array $rows): array
    {
        $statuses = [];

        foreach ($rows as $name => $id) {
            $status = $this->vectorQuery()->table(ApprovalStore::TABLE)->where('id', '=', $id)->value('status');

            if ($status !== null) {
                $statuses[$name] = (string) $status;
            }
        }

        return $statuses;
    }
}
