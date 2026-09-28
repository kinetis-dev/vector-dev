<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Responses\JsonResponse;
use Kinetis\Http\Routing\Router;
use Kinetis\Testing\TestApplication;
use Kinetis\Vector\Testing\ResourceClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The testing kit's export builder: CSV unless the caller names another
 * format. Runs against a route that echoes its query, so the test sees
 * exactly what the builder sent.
 */
final class ResourceClientExportTest extends TestCase
{
    public function testExportIsCsvUnlessTheCallerNamesAFormat(): void
    {
        $router = new Router();
        $router->register(ExportQueryEcho::class);
        $resource = new ResourceClient(TestApplication::withRouter($router)->client(), [], '/probe');

        $default = $resource->export(['columns' => 'name'])->assertOk()->json();
        self::assertSame('csv', $default['format'] ?? null);
        self::assertSame('name', $default['columns'] ?? null);

        $chosen = $resource->export(['format' => 'xlsx', 'columns' => 'name'])->assertOk()->json();
        self::assertSame('xlsx', $chosen['format'] ?? null, "the caller's format wins");
        self::assertSame('name', $chosen['columns'] ?? null);
    }
}

/** Answers the query it was sent. */
final readonly class ExportQueryEcho
{
    #[Get('/probe/export')]
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        return JsonResponse::create($request->getQueryParams());
    }
}
