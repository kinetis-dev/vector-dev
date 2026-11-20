<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Actions\ActionRequest;
use Kinetis\Vector\Actions\ActionResponse;
use Kinetis\Vector\Actions\VectorAction;
use Kinetis\Vector\Registry\VectorRegistry;

/**
 * An action that declares the records it acted on
 * (ActionResponse::withRecords()) has only those logged and audited, and
 * `affected` is their count; one it was not handed is ignored. An
 * action that declares nothing keeps the old behaviour: every record
 * audited, and an `affected` of 0 reported as the selection's size. The
 * actions are the test's own, added to products; they write nothing.
 */
final class ActionDeclaredRecordsTest extends VectorTestCase
{
    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $registry = $app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $data = $registry->toArray();

        foreach ([DeclaresTheFirstTwo::class => 'declares-the-first-two', DeclaresNone::class => 'declares-none', DeclaresNothing::class => 'declares-nothing'] as $class => $name) {
            $data['resources']['products']['actions'][$name] = [
                'name' => $name, 'class' => $class, 'label' => $name, 'icon' => null, 'confirmation' => null, 'destructive' => false,
                'standalone' => false, 'queued' => false, 'chunkSize' => 100, 'showOnIndex' => true, 'showOnDetail' => false, 'fields' => [],
            ];
        }

        $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (['declares-the-first-two', 'declares-none', 'declares-nothing'] as $name) {
            $this->vectorQuery()->table('vector_audit')->where('event', '=', "action:{$name}")->delete();
            $this->vectorQuery()->table('vector_action_events')->where('action', '=', "action:{$name}")->delete();
        }

        parent::tearDown();
    }

    public function testADeclaredSubsetIsAllThatIsAuditedAndCounted(): void
    {
        $ids = $this->productIds(4);

        $this->client->post('/vector/r/products/actions/declares-the-first-two', body: ['ids' => $ids], headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('type', 'message')
            ->assertJsonPath('affected', 2);

        // the identifier outside the selection is ignored
        self::assertSame(array_map(strval(...), array_slice($ids, 0, 2)), $this->audited('declares-the-first-two'));
        self::assertSame([array_map(strval(...), array_slice($ids, 0, 2))], $this->logged('declares-the-first-two'));
    }

    public function testDeclaringNoneAuditsNothingAndAffectsNone(): void
    {
        $this->client->post('/vector/r/products/actions/declares-none', body: ['ids' => $this->productIds(3)], headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('affected', 0);

        self::assertSame([], $this->audited('declares-none'));
        self::assertSame([[]], $this->logged('declares-none'), 'the run itself is still logged');
    }

    public function testDeclaringNothingKeepsAuditingEveryRecordAndCountsTheSelection(): void
    {
        $ids = $this->productIds(3);

        $this->client->post('/vector/r/products/actions/declares-nothing', body: ['ids' => $ids], headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('affected', 3);

        self::assertSame(array_map(strval(...), $ids), $this->audited('declares-nothing'));
    }

    /** @return list<string> the record ids audited for action $name, in order */
    private function audited(string $name): array
    {
        $rows = $this->vectorQuery()->table('vector_audit')->select('record_id')->where('resource', '=', 'products')
            ->where('event', '=', "action:{$name}")->orderBy('id', 'ASC')->get();

        return array_map(static fn (array $r): string => (string) $r['record_id'], $rows);
    }

    /** @return list<mixed> each logged run's record_ids */
    private function logged(string $name): array
    {
        $rows = $this->vectorQuery()->table('vector_action_events')->select('record_ids')->where('action', '=', "action:{$name}")->orderBy('id', 'ASC')->get();

        return array_map(static fn (array $r): mixed => json_decode((string) $r['record_ids'], true, 512, JSON_THROW_ON_ERROR), $rows);
    }

    /** @return list<int> */
    private function productIds(int $count): array
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);
        $rows = (new Query($link))->table('products')->select('id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->limit($count)->get();
        self::assertCount($count, $rows);

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }
}

/** Declares the first two records it was handed, and one it was not. */
final class DeclaresTheFirstTwo extends VectorAction
{
    #[\Override]
    public function handle(ActionRequest $request): ActionResponse
    {
        $ids = array_map(static fn (array $r): int|string => is_int($r['id']) || is_string($r['id']) ? $r['id'] : '', array_slice($request->records, 0, 2));

        return ActionResponse::message('Two of them.', 99)->withRecords([...$ids, 987654321]);
    }
}

/** Declares that it acted on none. */
final class DeclaresNone extends VectorAction
{
    #[\Override]
    public function handle(ActionRequest $request): ActionResponse
    {
        return ActionResponse::message('None of them.')->withRecords([]);
    }
}

/** Declares nothing. */
final class DeclaresNothing extends VectorAction
{
    #[\Override]
    public function handle(ActionRequest $request): ActionResponse
    {
        return ActionResponse::message('All of them, presumably.');
    }
}
