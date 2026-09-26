<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Testing\InteractsWithVector;

/**
 * Revert from the audit trail: `POST …/{id}/audit/{entry}/revert` puts
 * back the `from` values of one update, through the update path, audited
 * as a revert; a field changed again since then makes it a conflict.
 */
final class RevertAuditTest extends VectorTestCase
{
    use InteractsWithVector;

    public function testARevertPutsBackWhatTheEntryChanged(): void
    {
        $products = $this->vector($this->realAdminHeaders())->resource('products');
        [$id, $row] = $this->product();
        $categories = array_map('intval', array_column($this->shop()->table('categories')->select('id')->orderBy('id', 'ASC')->limit(2)->get(), 'id'));
        $otherCategory = $categories[0] === (int) $row['category_id'] ? $categories[1] : $categories[0];

        try {
            $products->update($id, ['stock' => (int) $row['stock'] + 7, 'status' => 'archived', 'category' => $otherCategory])->assertOk();
            $entry = $products->audit($id)->records()[0];
            self::assertSame('update', $entry['event']);

            $reverted = $products->revert($id, $entry['id'])->assertOk()->json()['data'];
            self::assertSame((int) $row['stock'], $reverted['stock']);
            self::assertSame($row['status'], $reverted['status']);
            self::assertSame((int) $row['category_id'], $reverted['category']['id']);

            $trail = $products->audit($id)->records();
            self::assertSame('revert', $trail[0]['event']);
            self::assertSame((int) $row['stock'], $trail[0]['changes']['stock']['to']);

            // a revert is itself revertable: reverting it redoes the update
            self::assertSame('archived', $products->revert($id, $trail[0]['id'])->assertOk()->json()['data']['status']);
        } finally {
            $this->restore($id, $row);
        }
    }

    public function testAFieldChangedSinceMakesItAConflict(): void
    {
        $products = $this->vector($this->realAdminHeaders())->resource('products');
        [$id, $row] = $this->product();

        try {
            $products->update($id, ['stock' => 1])->assertOk();
            $first = $products->audit($id)->records()[0]['id'];
            $products->update($id, ['stock' => 2])->assertOk();

            $products->revert($id, $first)->assertStatus(409);
            self::assertSame(2, (int) $this->shop()->table('products')->select('stock')->where('id', '=', $id)->value('stock'));
        } finally {
            $this->restore($id, $row);
        }
    }

    public function testOnlyAnUpdateOfThisRecordIsRevertable(): void
    {
        $h = $this->realAdminHeaders();
        $tags = $this->vector($h)->resource('tags');
        $tag = $tags->create(['name' => 'revert-' . bin2hex(random_bytes(3)), 'color' => '#123456'])->assertCreated()->id();
        [$productId] = $this->product();

        try {
            $created = $tags->audit($tag)->records()[0];
            self::assertSame('create', $created['event']);
            $tags->revert($tag, $created['id'])->assertInvalid('entry', 'not_revertable');

            // another record's entry, and an entry that does not exist, are not found
            $this->vector($h)->resource('products')->revert($productId, $created['id'])->assertNotFound();
            $tags->revert($tag, 999_999_999)->assertNotFound();
            $this->client->post("/vector/r/tags/{$tag}/audit/abc/revert", headers: $h)->assertStatus(404);
        } finally {
            $tags->delete($tag)->assertStatus(204);
        }
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function product(): array
    {
        $row = $this->shop()->table('products')->select('id', 'stock', 'status', 'category_id', 'updated_at')
            ->where('deleted_at', '=', null)->where('status', '=', 'active')->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);

        return [(int) $row['id'], $row];
    }

    /** @param array<string, mixed> $row */
    private function restore(int $id, array $row): void
    {
        $this->shop()->table('products')->where('id', '=', $id)->update([
            'stock' => $row['stock'], 'status' => $row['status'], 'category_id' => $row['category_id'], 'updated_at' => $row['updated_at'],
        ]);
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}
