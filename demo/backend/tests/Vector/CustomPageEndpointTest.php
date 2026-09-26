<?php

declare(strict_types=1);

namespace App\Tests\Vector;

/**
 * The demo's custom page reads an application endpoint in Vector's
 * `vector` middleware group: the admin's credentials and Vector's access
 * policy apply to it as to Vector's own routes.
 */
final class CustomPageEndpointTest extends VectorTestCase
{
    public function testTheCustomPagesEndpointAnswersAdminsOnly(): void
    {
        $overview = $this->client->get('/vector-demo/stock-overview', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($overview);
        self::assertIsInt($overview['threshold']);
        self::assertNotEmpty($overview['categories']);
        self::assertSame(['id', 'name', 'products', 'units', 'low'], array_keys($overview['categories'][0]));

        $this->client->get('/vector-demo/stock-overview')->assertStatus(401);
        $this->client->get('/vector-demo/stock-overview', headers: $this->editorHeaders())->assertStatus(403);
    }
}
