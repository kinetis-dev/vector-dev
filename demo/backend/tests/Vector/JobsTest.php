<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Authorization\AuthorizationResponse;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;

/** Queued actions, queued exports and imports, run inline through the sync queue, and what they leave behind. */
final class JobsTest extends VectorTestCase
{
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(AllowAllPolicy::class, new FrozenArchivedPolicy());
    }
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
        self::assertSame('nosniff', $download->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString('sandbox', $download->getHeaderLine('Content-Security-Policy'));
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
     * A failed flush keeps its insert pending in the manager: a row the
     * database refused once went out again with the next row's flush,
     * failing that row too, with the first row's reason.
     */
    public function testARowTheDatabaseRefusesDoesNotFailTheRowsAfterIt(): void
    {
        $link = $this->link();
        $categoryId = (int) (new Query($link))->table('categories')->select('id')->orderBy('id', 'ASC')->value('id');
        $stamp = bin2hex(random_bytes(3));
        $headers = 'sku,name,slug,status,category,priceCents,currency,stock,weightKg,isFeatured';
        // SKU-00020 is seeded: the first row passes validation and fails on the unique index
        $csv = "{$headers}\r\n"
            . "SKU-00020,Duplicate,dup-{$stamp},draft,{$categoryId},1500,EUR,3,0.4,false\r\n"
            . "NEW-{$stamp},After the duplicate,new-{$stamp},draft,{$categoryId},1500,EUR,3,0.4,false\r\n";
        $mapping = array_combine($fields = explode(',', $headers), $fields);

        try {
            $queued = $this->upload('/vector/r/products/import', $csv, ['mapping' => json_encode($mapping, JSON_THROW_ON_ERROR)])->assertStatus(202)->json();
            self::assertIsArray($queued);
            $job = $this->client->get("/vector/jobs/{$queued['jobId']}", headers: $this->realAdminHeaders())->assertOk()->assertJsonPath('data.status', 'done');
            $this->assertResult(['created' => 1, 'failed' => 1, 'updated' => 0], $job);

            $failed = $this->client->get("/vector/jobs/{$queued['jobId']}/download", headers: $this->realAdminHeaders())->assertOk()->body();
            self::assertStringContainsString('sku is already taken.', $failed);
            self::assertStringNotContainsString("NEW-{$stamp}", $failed, 'the row after the refused one was written');
            self::assertSame(1, (new Query($link))->table('products')->where('sku', '=', "NEW-{$stamp}")->count());
            self::assertSame(0, (new Query($link))->table('products')->where('slug', '=', "dup-{$stamp}")->count());
        } finally {
            (new Query($link))->table('products')->where('sku', '=', "NEW-{$stamp}")->delete();
        }
    }

    public function testReImportingAnExportWithUpsertByKeyUpdatesWithoutCreating(): void
    {
        $link = $this->link();
        $skus = ['SKU-00010', 'SKU-00011', 'SKU-00012'];
        $before = $this->products($skus);
        $export = $this->client->get('/vector/r/products/export', query: ['columns' => 'sku,name,priceCents,stock', 'filter' => ['sku' => ['in' => implode(',', $skus)]]], headers: $this->realAdminHeaders())
            ->assertOk()->body();

        // the admin corrects the prices in a spreadsheet and imports the file back
        $lines = explode("\r\n", trim(substr($export, 3)));
        self::assertSame('SKU,Name,Price,Stock', $lines[0]);
        $csv = $lines[0] . "\r\n" . implode("\r\n", array_map(static function (string $line): string {
            $cells = str_getcsv($line, escape: '');

            return "{$cells[0]},\"{$cells[1]}\"," . ((int) $cells[2] + 100) . ",{$cells[3]}";
        }, array_slice($lines, 1))) . "\r\n";
        $preview = $this->upload('/vector/r/products/import/preview', $csv)->assertOk()->json();
        self::assertIsArray($preview);
        self::assertSame(['SKU' => 'sku', 'Name' => 'name', 'Price' => 'priceCents', 'Stock' => 'stock'], $preview['mapping']);
        $count = (new Query($link))->table('products')->count();
        $auditBefore = $this->updateAudits($before);

        try {
            $job = $this->assertResult(['created' => 0, 'updated' => 3, 'failed' => 0], $this->import($csv, $preview['mapping'], 'upsert', 'sku'))
                ->assertJsonPath('data.hasFile', false)
                ->json();
            self::assertIsArray($job);
            $inbox = $this->client->get('/vector/notifications', headers: $this->realAdminHeaders())->assertOk()->json();
            self::assertIsArray($inbox);
            self::assertStringContainsString('0 created, 3 updated, 0 failed.', json_encode($inbox['data'][0], JSON_THROW_ON_ERROR));

            self::assertSame($count, (new Query($link))->table('products')->count(), 'no row was created');
            foreach ($this->products($skus) as $sku => $row) {
                self::assertSame((int) $before[$sku]['price_cents'] + 100, (int) $row['price_cents']);
            }
            self::assertSame($auditBefore + 3, $this->updateAudits($before), 'one audit entry per updated row');
        } finally {
            foreach ($before as $row) {
                (new Query($link))->table('products')->where('id', '=', (int) $row['id'])->update(['price_cents' => (int) $row['price_cents']]);
            }
        }
    }

    public function testUpdateByKeySendsAnUnknownKeyToTheFailedRowsAndUpsertCreatesIt(): void
    {
        $link = $this->link();
        $known = $this->products(['SKU-00020'])['SKU-00020'];
        $stamp = bin2hex(random_bytes(3));
        $categoryId = (int) (new Query($link))->table('categories')->select('id')->orderBy('id', 'ASC')->value('id');
        // an update row gives only what changes; a created row everything a create requires
        $csv = "sku,name,slug,status,category,priceCents,currency,stock,weightKg,isFeatured\r\n"
            . "SKU-00020,Renamed by import,,,,,,,,\r\n"
            . "NEW-{$stamp},Created by upsert,new-{$stamp},draft,{$categoryId},1500,EUR,3,0.4,false\r\n"
            . ",No key,,,,,,,,\r\n";
        $mapping = array_combine($headers = explode(',', 'sku,name,slug,status,category,priceCents,currency,stock,weightKg,isFeatured'), $headers);

        try {
            $update = $this->assertResult(['created' => 0, 'updated' => 1, 'failed' => 2], $this->import($csv, $mapping, 'update', 'sku'))
                ->json();
            self::assertIsArray($update);
            $failed = $this->client->get("/vector/jobs/{$update['data']['id']}/download", headers: $this->realAdminHeaders())->assertOk()->body();
            self::assertStringContainsString("NEW-{$stamp}", $failed);
            self::assertStringContainsString('No product has this sku.', $failed);
            self::assertStringContainsString('sku matches the row to a record and cannot be empty.', $failed);
            self::assertSame('Renamed by import', (new Query($link))->table('products')->where('id', '=', (int) $known['id'])->value('name'));
            self::assertSame(0, (new Query($link))->table('products')->where('sku', '=', "NEW-{$stamp}")->count());

            $this->assertResult(['created' => 1, 'updated' => 1, 'failed' => 1], $this->import($csv, $mapping, 'upsert', 'sku'));
            self::assertSame(1, (new Query($link))->table('products')->where('sku', '=', "NEW-{$stamp}")->count());
        } finally {
            (new Query($link))->table('products')->where('id', '=', (int) $known['id'])->update(['name' => $known['name']]);
            (new Query($link))->table('products')->where('sku', '=', "NEW-{$stamp}")->delete();
        }
    }

    public function testTheKeyAndTheModeAreValidatedBeforeAnythingIsQueued(): void
    {
        $csv = "id,sku,name\r\n1,SKU-00001,x\r\n";

        foreach ([
            [['sku' => 'sku', 'name' => 'name'], 'replace', 'sku', 'mode'],
            [['sku' => 'sku', 'name' => 'name'], 'update', '', 'key'],
            [['sku' => 'sku', 'name' => 'name'], 'update', 'name', 'key'], // not unique
            [['name' => 'name'], 'update', 'sku', 'key'], // not mapped
            [['sku' => 'sku', 'name' => 'name'], 'create', 'sku', 'key'], // a create matches nothing
            [['id' => 'id', 'name' => 'name'], 'create', '', 'mapping'], // the id is only ever a key
        ] as [$mapping, $mode, $key, $path]) {
            $errors = $this->upload('/vector/r/products/import', $csv, ['mapping' => json_encode($mapping, JSON_THROW_ON_ERROR), 'mode' => $mode, 'key' => $key])
                ->assertStatus(422)->json()['errors'];
            self::assertSame($path, $errors[0]['path'][0], "{$mode} by '{$key}'");
        }

        // the resource's own key matches rows too, and is not written
        $this->upload('/vector/r/products/import/preview', $csv)->assertOk()->assertJsonPath('mapping.id', 'id');
        $id = (int) $this->products(['SKU-00001'])['SKU-00001']['id'];
        $name = (string) $this->products(['SKU-00001'])['SKU-00001']['name'];

        try {
            $this->import("id,name\r\n{$id},By id\r\n", ['id' => 'id', 'name' => 'name'], 'update', 'id')->assertJsonPath('data.result.updated', 1);
            self::assertSame('By id', $this->products(['SKU-00001'])['SKU-00001']['name']);
        } finally {
            (new Query($this->link()))->table('products')->where('id', '=', $id)->update(['name' => $name]);
        }
    }

    public function testEachUpdatedRowIsAuthorizedByTheUpdatePolicy(): void
    {
        $link = $this->link();
        $archived = (new Query($link))->table('products')->select('id', 'sku', 'name')->where('status', '=', 'archived')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->first();
        $draft = $this->products(['SKU-00030'])['SKU-00030'];
        self::assertIsArray($archived);
        $csv = "sku,name\r\n{$archived['sku']},Thawed\r\nSKU-00030,Edited\r\n";

        try {
            $job = $this->assertResult(['created' => 0, 'updated' => 1, 'failed' => 1], $this->import($csv, ['sku' => 'sku', 'name' => 'name'], 'update', 'sku'))
                ->json();
            self::assertIsArray($job);
            $failed = $this->client->get("/vector/jobs/{$job['data']['id']}/download", headers: $this->realAdminHeaders())->assertOk()->body();
            self::assertStringContainsString('Archived products are frozen.', $failed);
            self::assertSame($archived['name'], (new Query($link))->table('products')->where('id', '=', (int) $archived['id'])->value('name'));
        } finally {
            (new Query($link))->table('products')->where('id', '=', (int) $draft['id'])->update(['name' => $draft['name']]);
        }
    }

    public function testAnImportThatCannotWriteIsForbiddenBeforeItIsQueued(): void
    {
        // users are never created from the admin, but they are updated: by email, which is unique
        $csv = "email,name\r\nnobody@vector.invalid,Nobody\r\n";
        $this->upload('/vector/r/users/import', $csv, ['mapping' => json_encode(['name' => 'name'], JSON_THROW_ON_ERROR)])->assertStatus(403);
        $this->upload('/vector/r/users/import', $csv, ['mapping' => json_encode(['email' => 'email', 'name' => 'name'], JSON_THROW_ON_ERROR), 'mode' => 'upsert', 'key' => 'email'])->assertStatus(202);
        $queued = $this->upload('/vector/r/users/import', $csv, ['mapping' => json_encode(['email' => 'email', 'name' => 'name'], JSON_THROW_ON_ERROR), 'mode' => 'update', 'key' => 'email'])->assertStatus(202)->json();
        self::assertIsArray($queued);
        $this->assertResult(['created' => 0, 'updated' => 0, 'failed' => 1], $this->client->get("/vector/jobs/{$queued['jobId']}", headers: $this->realAdminHeaders())->assertOk());
        self::assertSame(0, (new Query($this->link()))->table('users')->where('email', '=', 'nobody@vector.invalid')->count(), 'an upsert row is still authorized as the create it would be');
    }

    /**
     * Queues an import (the sync queue runs it) and returns its job.
     *
     * @param array<string, string> $mapping
     */
    private function import(string $csv, array $mapping, string $mode, string $key): \Kinetis\Testing\TestResponse
    {
        $queued = $this->upload('/vector/r/products/import', $csv, ['mapping' => json_encode($mapping, JSON_THROW_ON_ERROR), 'mode' => $mode, 'key' => $key])
            ->assertStatus(202)->json();
        self::assertIsArray($queued);

        return $this->client->get("/vector/jobs/{$queued['jobId']}", headers: $this->realAdminHeaders())->assertOk()->assertJsonPath('data.status', 'done');
    }

    /**
     * The job's counts, whatever order the JSON column keeps them in.
     *
     * @param array<string, int> $expected
     */
    private function assertResult(array $expected, \Kinetis\Testing\TestResponse $job): \Kinetis\Testing\TestResponse
    {
        $result = $job->json()['data']['result'] ?? null;
        self::assertIsArray($result);
        ksort($result);
        ksort($expected);
        self::assertSame($expected, $result);

        return $job;
    }

    /**
     * @param list<string> $skus
     * @return array<string, array<string, mixed>> sku => row
     */
    private function products(array $skus): array
    {
        $rows = (new Query($this->link()))->table('products')->select('id', 'sku', 'name', 'price_cents')->whereIn('sku', $skus)->get();

        return array_column($rows, null, 'sku');
    }

    /** @param array<string, array<string, mixed>> $rows */
    private function updateAudits(array $rows): int
    {
        return $this->vectorQuery()->table('vector_audit')->where('resource', '=', 'products')->where('event', '=', 'update')
            ->whereIn('record_id', array_map(static fn (array $r): string => (string) $r['id'], array_values($rows)))->count();
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

/** The default policy, except that an archived product is not updated (nothing else in these tests updates one). */
final class FrozenArchivedPolicy extends ResourcePolicy
{
    public function update(CurrentUserInterface $user, array $record): bool|AuthorizationResponse
    {
        return ($record['status'] ?? null) === 'archived' && array_key_exists('sku', $record) ? AuthorizationResponse::deny('Archived products are frozen.') : true;
    }
}
