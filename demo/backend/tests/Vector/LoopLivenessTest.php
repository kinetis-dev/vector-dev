<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Testing\LoopLiveness;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Vector's list, show, write and action paths let the event loop turn
 * while they wait on the database. Each request is driven while another
 * process holds a write lock on `products` (tests/Fixtures/hold-products-lock.php),
 * so its statements genuinely wait on MySQL for many sentinel intervals.
 *
 * Every path runs twice: on the native drivers, as a persistent worker
 * gets them, the loop turns; on PDO, which blocks, the same request
 * reports that it did not — what shows the proof can fail.
 */
final class LoopLivenessTest extends VectorTestCase
{
    /** How long the lock is held: fifteen of LoopLiveness's 20 ms sentinel intervals. */
    private const int HOLD_MS = 300;

    private ?int $productId = null;

    /** @return array<string, array{string, bool}> */
    public static function drivers(): array
    {
        return [
            'native yields' => ['native', true],
            'pdo blocks' => ['pdo', false],
        ];
    }

    /** @return array<string, string> */
    #[\Override]
    protected function configOverrides(): array
    {
        $driver = $this->providedData()[0] ?? 'native';
        self::assertIsString($driver);

        // every connection the requests touch: the MySQL shop and the PostgreSQL admin and support desk
        return [
            ...parent::configOverrides(),
            'DB_DRIVER' => $driver,
            'DB_ADMIN_DRIVER' => $driver,
            'DB_SUPPORT_DRIVER' => $driver,
        ];
    }

    #[DataProvider('drivers')]
    public function testListingProducts(string $driver, bool $yields): void
    {
        $response = null;
        $turned = $this->whileProductsAreLocked(function () use (&$response): void {
            $response = $this->client->get('/vector/r/products', query: ['perPage' => 5], headers: $this->adminHeaders());
        });

        self::assertNotNull($response);
        $response->assertOk();
        self::assertSame($yields, $turned, "listing on {$driver}");
    }

    #[DataProvider('drivers')]
    public function testShowingAProduct(string $driver, bool $yields): void
    {
        $id = $this->createProduct();
        $response = null;
        $turned = $this->whileProductsAreLocked(function () use ($id, &$response): void {
            $response = $this->client->get("/vector/r/products/{$id}", headers: $this->adminHeaders());
        });

        self::assertNotNull($response);
        $response->assertOk()->assertJsonPath('data.id', $id);
        self::assertSame($yields, $turned, "showing on {$driver}");
    }

    #[DataProvider('drivers')]
    public function testUpdatingAProduct(string $driver, bool $yields): void
    {
        $id = $this->createProduct();
        $response = null;
        $turned = $this->whileProductsAreLocked(function () use ($id, &$response): void {
            $response = $this->client->request('PATCH', "/vector/r/products/{$id}", body: ['name' => 'Renamed while locked'], headers: [...$this->adminHeaders(), 'If-Match' => '"1"']);
        });

        self::assertNotNull($response);
        $response->assertOk()->assertJsonPath('data.name', 'Renamed while locked');
        self::assertSame($yields, $turned, "updating on {$driver}");
    }

    #[DataProvider('drivers')]
    public function testRunningAnAction(string $driver, bool $yields): void
    {
        $id = $this->createProduct();
        $response = null;
        $turned = $this->whileProductsAreLocked(function () use ($id, &$response): void {
            $response = $this->client->post('/vector/r/products/actions/adjust-stock', body: ['ids' => [$id], 'fields' => ['delta' => 2]], headers: $this->adminHeaders());
        });

        self::assertNotNull($response);
        $response->assertOk()->assertJsonPath('affected', 1);
        self::assertSame(5, (int) (new Query($this->link()))->table('products')->where('id', '=', $id)->value('stock'));
        self::assertSame($yields, $turned, "running an action on {$driver}");
    }

    protected function tearDown(): void
    {
        if ($this->productId !== null) {
            $this->client->request('DELETE', "/vector/r/products/{$this->productId}", headers: $this->adminHeaders(), query: ['force' => '1'])->assertStatus(204);
        }

        parent::tearDown();
    }

    /**
     * Starts the lock holder, waits until it holds the lock — blocking
     * setup, outside what LoopLiveness observes — and reports whether the
     * loop turned while $request ran against the locked table.
     */
    private function whileProductsAreLocked(callable $request): bool
    {
        $config = $this->app->get(Config::class);
        self::assertInstanceOf(Config::class, $config);

        $holder = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/hold-products-lock.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'LOCK_DB_HOST' => $config->required('DB_HOST'),
                'LOCK_DB_PORT' => $config->string('DB_PORT', '3306'),
                'LOCK_DB_NAME' => $config->required('DB_NAME'),
                'LOCK_DB_USER' => $config->required('DB_USER'),
                'LOCK_DB_PASSWORD' => $config->required('DB_PASSWORD'),
                'LOCK_HOLD_MS' => (string) self::HOLD_MS,
            ],
        );

        if ($holder === false) {
            self::fail('Could not start the lock holder.');
        }

        try {
            if (fgets($pipes[1]) !== "locked\n") {
                // reading stderr waits for the holder to exit, so only once it has failed
                self::fail('The lock holder did not take the lock: ' . stream_get_contents($pipes[2]));
            }

            $start = hrtime(true);
            $turned = LoopLiveness::turnedDuring($request);
            $waitedMs = (hrtime(true) - $start) / 1e6;
            // the request waited on the lock, not merely on work of its own
            self::assertGreaterThan(self::HOLD_MS / 2, $waitedMs, 'The request did not wait on the lock.');

            return $turned;
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($holder);
        }
    }

    /** A product of the test's own, stock 3, removed after the test. */
    private function createProduct(): int
    {
        $category = (new Query($this->link()))->table('categories')->select('id')->orderBy('id', 'ASC')->first();
        self::assertIsArray($category);
        $sku = 'SKU-LIVE-' . bin2hex(random_bytes(4));

        $created = $this->client->post('/vector/r/products', body: [
            'sku' => $sku, 'name' => 'Liveness product', 'slug' => 'liveness-' . $sku, 'status' => 'draft',
            'category' => (int) $category['id'], 'priceCents' => 100, 'currency' => 'EUR', 'stock' => 3, 'weightKg' => 1,
            'isFeatured' => false,
        ], headers: $this->adminHeaders())->assertCreated()->json();
        self::assertIsArray($created);
        self::assertIsInt($created['data']['id']);

        return $this->productId = $created['data']['id'];
    }

    private function link(): MysqlLink
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return $link;
    }
}
