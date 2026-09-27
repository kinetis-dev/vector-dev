<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use OpenSpout\Reader\XLSX\Reader;

/**
 * XLSX exports: always a job (openspout writes through temporary files,
 * which only the queue worker may block on), with typed cells read back
 * here through openspout's own reader.
 */
final class XlsxExportTest extends VectorTestCase
{
    public function testTheFormatsOfferedIncludeXlsxWhenThePackageAndAQueueAreThere(): void
    {
        $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->assertJsonPath('capabilities.exportFormats', ['csv', 'xlsx']);
    }

    public function testAnXlsxExportIsQueuedWithTypedCells(): void
    {
        $skus = ['SKU-00001', 'SKU-00002', 'SKU-00003'];
        $rows = $this->export('products', ['columns' => 'sku,name,priceCents,currency,weightKg,isFeatured,releasedAt', 'filter' => ['sku' => ['in' => implode(',', $skus)]], 'sort' => 'sku']);
        $stored = array_column((new Query($this->link()))->table('products')->select('sku', 'price_cents', 'currency', 'is_featured', 'released_at')->whereIn('sku', $skus)->get(), null, 'sku');

        self::assertSame(['SKU', 'Name', 'Price', 'Currency', 'Weight (kg)', 'Featured', 'Released at (UTC)'], $rows[0]);
        self::assertCount(4, $rows);

        foreach (array_slice($rows, 1) as $row) {
            $product = $stored[$row[0]];
            // money in major units of the row's own currency, a real number
            self::assertIsFloat($row[2]);
            self::assertEqualsWithDelta((int) $product['price_cents'] / 100, $row[2], 0.001);
            self::assertSame($product['currency'], $row[3]);
            self::assertIsBool($row[5]);
            self::assertSame((bool) $product['is_featured'], $row[5]);

            if ($product['released_at'] !== null) {
                self::assertInstanceOf(\DateTimeInterface::class, $row[6], 'a date-time is a real date cell');
            }
        }
    }

    public function testADateIsADateCellAndTextIsNeverAFormula(): void
    {
        $link = $this->link();
        $stamp = bin2hex(random_bytes(3));
        $name = '=HYPERLINK("https://example.com","x") ' . $stamp;
        $id = (int) (new Query($link))->table('tags')->insertGetId(['name' => $name, 'color' => '#000000']);

        try {
            $rows = $this->export('tags', ['columns' => 'name', 'q' => $stamp]);
            self::assertSame("'" . $name, $rows[1][0], 'the same guard as CSV, in a text cell');
        } finally {
            (new Query($link))->table('tags')->where('id', '=', $id)->delete();
        }

        $customer = (new Query($link))->table('customers')->select('email', 'birth_date')->where('birth_date', '!=', null)->orderBy('id', 'ASC')->first();
        self::assertIsArray($customer);
        $rows = $this->export('customers', ['columns' => 'email,birthDate', 'q' => $customer['email']]);
        self::assertSame($customer['email'], $rows[1][0]);
        self::assertInstanceOf(\DateTimeInterface::class, $rows[1][1]);
        self::assertSame(substr((string) $customer['birth_date'], 0, 10), $rows[1][1]->format('Y-m-d'));
    }

    public function testTwentyThousandRowsExportAndDownload(): void
    {
        $link = $this->link();
        $stamp = 'xlsx-' . bin2hex(random_bytes(3));

        try {
            foreach (array_chunk(range(1, 20_000), 1000) as $chunk) {
                (new Query($link))->table('tags')->insert(array_map(static fn (int $n): array => ['name' => "{$stamp}-{$n}", 'color' => '#336699'], $chunk));
            }

            $rows = $this->export('tags', ['columns' => 'name,color', 'q' => $stamp, 'sort' => 'id']);

            self::assertCount(20_001, $rows);
            self::assertSame(["{$stamp}-1", '#336699'], $rows[1]);
            self::assertSame(["{$stamp}-20000", '#336699'], $rows[20_000]);
        } finally {
            (new Query($link))->table('tags')->where('name', 'LIKE', "{$stamp}-%")->delete();
        }
    }

    /**
     * Queues an XLSX export (the sync queue runs it) and reads its file back.
     *
     * @param array<string, mixed> $query
     * @return list<list<mixed>>
     */
    private function export(string $resource, array $query): array
    {
        $queued = $this->client->get("/vector/r/{$resource}/export", query: [...$query, 'format' => 'xlsx'], headers: $this->realAdminHeaders())
            ->assertStatus(202)->assertJsonPath('type', 'job')->json();
        self::assertIsArray($queued);
        $this->client->get("/vector/jobs/{$queued['jobId']}", headers: $this->realAdminHeaders())->assertOk()
            ->assertJsonPath('data.status', 'done')->assertJsonPath('data.hasFile', true);
        $download = $this->client->get("/vector/jobs/{$queued['jobId']}/download", headers: $this->realAdminHeaders())->assertOk();
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $download->getHeaderLine('Content-Type'));
        self::assertStringEndsWith('.xlsx"', $download->getHeaderLine('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        self::assertIsString($path);
        file_put_contents($path, $download->body());

        try {
            $reader = new Reader();
            $reader->open($path);
            $rows = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_values($row->toArray());
                }
            }

            $reader->close();

            return $rows;
        } finally {
            unlink($path);
        }
    }

    private function link(): MysqlLink
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return $link;
    }
}
