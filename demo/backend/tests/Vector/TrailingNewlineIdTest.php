<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Auth\DemoActor;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Exception\RecordNotFoundException;
use Kinetis\Vector\Query\ResourceService;

/**
 * An integer identifier is digits and nothing after them: `$` alone also
 * matches before a final newline, so "42\n" once read as record 42. A
 * route segment reaches the router still percent-encoded, so the newline
 * arrives decoded through a query value, or through the service a route
 * calls with the segment.
 */
final class TrailingNewlineIdTest extends VectorTestCase
{
    public function testACursorWithATrailingNewlineIsRefused(): void
    {
        $id = $this->firstProductId();

        $this->client->get('/vector/r/products', query: ['cursor' => (string) $id, 'perPage' => 3], headers: $this->adminHeaders())->assertOk();
        $this->client->get('/vector/r/products', query: ['cursor' => "{$id}\n", 'perPage' => 3], headers: $this->adminHeaders())->assertStatus(422);
    }

    public function testARecordIdWithATrailingNewlineNamesNoRecord(): void
    {
        $id = $this->firstProductId();
        $scope = $this->app->createRequestScope();

        try {
            $scope->instance(CurrentUserInterface::class, new DemoActor($this->adminId(), 'Test', true));
            $service = $scope->get(ResourceService::class);
            self::assertInstanceOf(ResourceService::class, $service);
            $products = $service->resource('products');

            self::assertNotSame([], $service->show($products, (string) $id), 'the same id without the newline is a record');

            try {
                $service->show($products, "{$id}\n");
                self::fail('An identifier with a trailing newline named a record.');
            } catch (RecordNotFoundException) {
                // the 404 the record route answers with
            }
        } finally {
            $scope->dispose();
        }
    }

    private function firstProductId(): int
    {
        return (int) $this->shop()->table('products')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->value('id');
    }

    private function adminId(): int
    {
        return (int) $this->shop()->table('users')->where('email', '=', 'admin@vector.local')->value('id');
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}
