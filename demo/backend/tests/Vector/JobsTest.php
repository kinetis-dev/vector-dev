<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;

/** Queued actions, queued exports and imports, run inline through the sync queue, and what they leave behind. */
final class JobsTest extends VectorTestCase
{
    public function testAQueuedActionRunsInChunksAndReportsIntoItsJobRow(): void
    {
        $link = $this->link();
        $before = (new Query($link))->table('products')->select('id', 'price_cents')->where('status', '=', 'draft')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->limit(3)->get();
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $before);

        $queued = $this->client->post('/vector/r/products/actions/reprice-products', body: ['ids' => $ids, 'fields' => ['percent' => 10]], headers: $this->realAdminHeaders())
            ->assertStatus(202)
            ->assertJsonPath('type', 'job')
            ->json();
        self::assertIsArray($queued);
        $jobId = $queued['jobId'];

        // the sync queue already ran it
        $job = $this->client->get("/vector/jobs/{$jobId}", headers: $this->realAdminHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.kind', 'action')
            ->assertJsonPath('data.processed', 3)
            ->assertJsonPath('data.total', 3)
            ->json();
        self::assertIsArray($job);
        self::assertSame(3, $job['data']['result']['affected']);

        foreach ($before as $row) {
            $now = (int) (new Query($link))->table('products')->where('id', '=', (int) $row['id'])->value('price_cents');
            self::assertSame((int) round((int) $row['price_cents'] * 1.1), $now);
            (new Query($link))->table('products')->where('id', '=', (int) $row['id'])->update(['price_cents' => (int) $row['price_cents']]);
        }

        // a notification landed, the job lists for its actor only, and the dialog is validated before queueing
        $inbox = $this->client->get('/vector/notifications', headers: $this->realAdminHeaders())->assertOk()->json();
        self::assertIsArray($inbox);
        self::assertGreaterThanOrEqual(1, $inbox['unread']);
        self::assertStringContainsString('Reprice', $inbox['data'][0]['title']);
        $this->client->get("/vector/jobs/{$jobId}", headers: ['Authorization' => 'Bearer ' . $this->tokenFor('42')])->assertNotFound();
        $this->client->post('/vector/r/products/actions/reprice-products', body: ['ids' => $ids, 'fields' => ['percent' => 'x']], headers: $this->realAdminHeaders())->assertValidationError('percent');
        $this->client->post('/vector/notifications/read-all', headers: $this->realAdminHeaders())->assertOk();
        $this->client->get('/vector/me', headers: $this->realAdminHeaders())->assertOk()->assertJsonPath('unreadNotifications', 0);
    }

    public function testAnExportAboveTheInlineLimitBecomesAJobWithADownload(): void
    {
        $queued = $this->client->get('/vector/r/products/export', query: ['columns' => 'sku,name'], headers: $this->realAdminHeaders())
            ->assertStatus(202)
            ->assertJsonPath('type', 'job')
            ->json();
        self::assertIsArray($queued);
        $jobId = $queued['jobId'];

        $this->client->get("/vector/jobs/{$jobId}", headers: $this->realAdminHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.hasFile', true);
        $download = $this->client->get("/vector/jobs/{$jobId}/download", headers: $this->realAdminHeaders())->assertOk();
        self::assertStringStartsWith('text/csv', $download->getHeaderLine('Content-Type'));
        self::assertGreaterThan(100, substr_count($download->body(), "\r\n"));

        // under the limit the export stays inline
        $this->client->get('/vector/r/products/export', query: ['filter' => ['status' => 'archived']], headers: $this->realAdminHeaders())->assertOk();
        $jobs = $this->client->get('/vector/jobs', headers: $this->realAdminHeaders())->assertOk()->json();
        self::assertIsArray($jobs);
        self::assertSame($jobId, $jobs['data'][0]['id']);
    }

    public function testAnImportPreviewsMapsCreatesAndReportsFailedRows(): void
    {
        $link = $this->link();
        $categoryId = (int) (new Query($link))->table('categories')->select('id')->orderBy('id', 'ASC')->value('id');
        $tagId = (int) (new Query($link))->table('tags')->select('id')->orderBy('id', 'ASC')->value('id');
        $stamp = bin2hex(random_bytes(3));
        // an active product needs its release date (the demo's requiredWhen)
        $csv = "SKU,Name,slug,status,category,priceCents,currency,stock,weightKg,isFeatured,tags,releasedAt,ignored\r\n"
            . "IMP-{$stamp}-1,Imported one,imported-one-{$stamp},draft,{$categoryId},1999,EUR,5,0.5,false,{$tagId},,x\r\n"
            . "IMP-{$stamp}-2,Imported two,imported-two-{$stamp},active,{$categoryId},2999,EUR,1,1,true,,2026-01-15T09:00:00Z,x\r\n"
            . "IMP-{$stamp}-3,Broken,imported-three-{$stamp},nope,{$categoryId},abc,EUR,1,1,false,,,x\r\n";

        $preview = $this->upload('/vector/r/products/import/preview', $csv)->assertOk()->json();
        self::assertIsArray($preview);
        self::assertSame(3, $preview['rows']);
        self::assertSame('sku', $preview['mapping']['SKU']);
        self::assertSame('name', $preview['mapping']['Name']);
        self::assertArrayNotHasKey('ignored', $preview['mapping']);
        self::assertCount(3, $preview['sample']);

        $mapping = $preview['mapping'];
        $this->upload('/vector/r/products/import', $csv, ['mapping' => json_encode(['SKU' => 'bogus'], JSON_THROW_ON_ERROR)])->assertStatus(422);

        $queued = $this->upload('/vector/r/products/import', $csv, ['mapping' => json_encode($mapping, JSON_THROW_ON_ERROR)])
            ->assertStatus(202)
            ->assertJsonPath('rows', 3)
            ->json();
        self::assertIsArray($queued);
        $jobId = $queued['jobId'];

        $job = $this->client->get("/vector/jobs/{$jobId}", headers: $this->realAdminHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.result.created', 2)
            ->assertJsonPath('data.result.failed', 1)
            ->assertJsonPath('data.hasFile', true)
            ->json();
        self::assertIsArray($job);
        $failed = $this->client->get("/vector/jobs/{$jobId}/download", headers: $this->realAdminHeaders())->assertOk()->body();
        self::assertStringContainsString("IMP-{$stamp}-3", $failed);
        self::assertStringContainsString('status', $failed);

        $created = (new Query($link))->table('products')->where('sku', 'LIKE', "IMP-{$stamp}-%")->orderBy('sku', 'ASC')->get();
        self::assertCount(2, $created);
        self::assertSame('active', $created[1]['status']);
        self::assertSame(1, (new Query($link))->table('product_tag')->where('product_id', '=', (int) $created[0]['id'])->count(), 'the manyToMany column attached a tag');
        self::assertGreaterThanOrEqual(2, $this->vectorQuery()->table('vector_audit')->where('resource', '=', 'products')->where('event', '=', 'create')->whereIn('record_id', array_map(static fn (array $r): string => (string) $r['id'], $created))->count());

        (new Query($link))->table('products')->where('sku', 'LIKE', "IMP-{$stamp}-%")->delete();
    }

    /**
     * A real multipart body: the framework parses the stream itself.
     *
     * @param array<string, string> $fields
     */
    private function upload(string $uri, string $csv, array $fields = []): \Kinetis\Testing\TestResponse
    {
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= "--vector\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }

        $body .= "--vector\r\nContent-Disposition: form-data; name=\"file\"; filename=\"products.csv\"\r\nContent-Type: text/csv\r\n\r\n{$csv}\r\n--vector--\r\n";

        return $this->client->raw('POST', $uri, $body, ['Content-Type' => 'multipart/form-data; boundary=vector', ...$this->realAdminHeaders()]);
    }

    private function link(): MysqlLink
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return $link;
    }
}
