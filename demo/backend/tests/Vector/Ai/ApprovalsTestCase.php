<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\QueryBuilder\Query;
use Kinetis\Testing\TestResponse;
use Kinetis\VectorAi\Approvals\ApprovalId;
use Kinetis\VectorAi\Approvals\ApprovalStore;
use NeuronAI\Testing\RequestRecord;

/**
 * The assistant proposing writes, against the `fake` provider, with
 * `assistantWrites` on: propose() scripts one run that calls a write
 * tool and answers, and returns its `data-approval` frame; decide()
 * approves or rejects. Every test changes the same product's stock and
 * puts it back; the approvals, audit rows and action events of the
 * tests' own actors are removed after each test.
 */
abstract class ApprovalsTestCase extends AssistantTestCase
{
    /** What the scripted run answers once it proposed. */
    protected const string PROPOSED_ANSWER = 'I proposed changing the stock of products#%d; approve it to apply.';

    protected int $product;

    protected int $stock;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $row = $this->shop()->table('products')->select('id', 'stock')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);
        $this->product = (int) $row['id'];
        $this->stock = (int) $row['stock'];
        $this->settings(['assistantWrites' => true]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->shop()->table('products')->where('id', '=', $this->product)->update(['stock' => $this->stock]);
        $this->vectorQuery()->table(ApprovalStore::TABLE)->where('actor_id', 'LIKE', self::ACTOR_PREFIX . '%')->delete();
        $this->vectorQuery()->table('vector_audit')->where('actor_id', 'LIKE', self::ACTOR_PREFIX . '%')->delete();
        $this->vectorQuery()->table('vector_action_events')->where('actor_id', 'LIKE', self::ACTOR_PREFIX . '%')->delete();
        parent::tearDown();
    }

    /**
     * The arguments of an update of the test product's stock to $stock.
     *
     * @return array<string, mixed>
     */
    protected function update(int $stock): array
    {
        return ['resource' => 'products', 'id' => (string) $this->product, 'values' => ['stock' => $stock]];
    }

    /**
     * One run that calls $tool with $arguments, then answers.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, string> $headers
     * @return array{status: int, frames: list<array<string, mixed>>, approvals: list<array<string, mixed>>}
     */
    protected function propose(array $arguments, string $tool = 'vector_update', int $actor = 1, array $headers = []): array
    {
        $this->script([self::QUESTION => self::rounds([[['name' => $tool, 'arguments' => $arguments]]], sprintf(self::PROPOSED_ANSWER, $this->product))]);
        $turn = $this->turn(headers: $headers, actor: $actor);
        $approvals = array_map(static function (array $frame): array {
            self::assertIsArray($frame['data']);

            return $frame['data'];
        }, self::ofType($turn['frames'], 'data-approval'));

        return ['status' => $turn['status'], 'frames' => $turn['frames'], 'approvals' => $approvals];
    }

    /**
     * The one approval a run that proposed $arguments announced.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function proposed(array $arguments, string $tool = 'vector_update', int $actor = 1, array $headers = []): array
    {
        $run = $this->propose($arguments, $tool, $actor, $headers);
        self::assertSame(200, $run['status']);
        self::assertCount(1, $run['approvals'], 'the run announced one proposal');

        return $run['approvals'][0];
    }

    /**
     * POST /vector/ai/approvals/{id}/{$verb} as actor $actor, or with $headers.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    protected function decide(string $id, string $verb = 'approve', int $actor = 1, array $body = [], array $headers = []): TestResponse
    {
        return $this->client->post("/vector/ai/approvals/{$id}/{$verb}", $body, [...$this->aiHeaders($actor), ...$headers]);
    }

    /**
     * Headers of actor $n (`9902<n>`) impersonated by $impersonator.
     *
     * @return array<string, string>
     */
    protected function impersonated(int $n, int $impersonator): array
    {
        $issuer = $this->app->get(JwtIssuer::class);
        self::assertInstanceOf(JwtIssuer::class, $issuer);
        $subject = self::ACTOR_PREFIX . str_pad((string) $n, 2, '0', STR_PAD_LEFT);

        return ['Authorization' => 'Bearer ' . $issuer->issue($subject, ['name' => 'Test', 'vector' => true, 'impersonator' => $impersonator], ttlSeconds: 300)];
    }

    /** @return array<string, mixed>|null the stored row of approval $publicId */
    protected function row(string $publicId): ?array
    {
        $id = ApprovalId::parse($publicId);
        self::assertNotNull($id, "{$publicId} is an approval id");
        $row = $this->approvalsTable()->where('id', '=', $id)->first();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> the approvals stored for the tests' actors */
    protected function rows(): array
    {
        return $this->approvalsTable()->where('actor_id', 'LIKE', self::ACTOR_PREFIX . '%')->get();
    }

    protected function stockNow(): int
    {
        return (int) $this->shop()->table('products')->where('id', '=', $this->product)->value('stock');
    }

    /** @return list<array<string, mixed>> the test product's audit rows the tests' actors wrote */
    protected function auditRows(): array
    {
        return $this->vectorQuery()->table('vector_audit')
            ->where('resource', '=', 'products')
            ->where('record_id', '=', (string) $this->product)
            ->where('actor_id', 'LIKE', self::ACTOR_PREFIX . '%')
            ->get();
    }

    /** @return list<string> the names of the tools a recorded call offered the model, in order */
    protected static function toolNames(RequestRecord $call): array
    {
        return array_values(array_map(static fn (object $tool): string => (string) $tool->getName(), $call->tools));
    }

    private function approvalsTable(): Query
    {
        return $this->vectorQuery()->table(ApprovalStore::TABLE);
    }
}
