<?php

declare(strict_types=1);

namespace App\Tests\Vector;

/**
 * `GET /vector/openapi.json` is served exactly where the framework
 * serves its own `/openapi.json`: OPENAPI_ENVIRONMENTS naming APP_ENV.
 * Each subclass boots under one setting. Where it is closed, the path
 * answers what a path no route matches answers, byte for byte, for
 * every method and with or without credentials — so never a 401.
 */
abstract class OpenApiExposureTestCase extends VectorTestCase
{
    private const array METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /** The OPENAPI_ENVIRONMENTS this case boots with. */
    abstract protected function environments(): string;

    abstract protected function served(): bool;

    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'APP_ENV' => 'development', 'OPENAPI_ENVIRONMENTS' => $this->environments()];
    }

    public function testTheEndpointFollowsTheFrameworksOwnDocument(): void
    {
        $framework = $this->client->get('/openapi.json');

        if (!$this->served()) {
            $framework->assertStatus(404);

            foreach (self::METHODS as $method) {
                foreach ([[], ['Authorization' => 'Bearer not-a-token'], $this->adminHeaders()] as $headers) {
                    foreach (['/vector/openapi.json', '/vector/openapi.json/'] as $path) {
                        $this->assertUnrouted($method, $path, $headers);
                    }
                }
            }

            return;
        }

        $framework->assertOk();
        $document = $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($document);
        self::assertSame('3.1.0', $document['openapi']);
        self::assertNotEmpty($document['paths']);

        // open is not public: the vector group still stands in front of it
        $this->client->get('/vector/openapi.json')->assertStatus(401);
        $this->client->get('/vector/openapi.json', headers: $this->editorHeaders())->assertStatus(403);
        $this->client->request('POST', '/vector/openapi.json', headers: $this->adminHeaders())->assertStatus(405);
    }

    /**
     * $method $path answers as the same request for a path no route
     * matches does: status, headers and body, the path aside.
     *
     * @param array<string, string> $headers
     */
    private function assertUnrouted(string $method, string $path, array $headers): void
    {
        $unknown = '/vector/no-such-route.json';
        $expected = $this->client->request($method, $unknown, headers: $headers);
        $actual = $this->client->request($method, $path, headers: $headers);
        $label = "{$method} {$path} " . ($headers === [] ? 'anonymous' : 'with credentials');

        self::assertSame(404, $actual->getStatusCode(), $label);
        self::assertSame(self::headers($expected), self::headers($actual), $label);
        self::assertSame(
            // the message names the path, with its slashes escaped or not
            str_replace([$unknown, addcslashes($unknown, '/')], [$path, addcslashes($path, '/')], $expected->body()),
            $actual->body(),
            $label,
        );
        self::assertStringContainsString('No route matches path', $actual->body(), $label);
    }

    /**
     * The response's headers, its Content-Security-Policy nonce aside:
     * the security headers draw a fresh one for every response.
     *
     * @return array<string, list<string>>
     */
    private static function headers(\Psr\Http\Message\ResponseInterface $response): array
    {
        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = array_map(static fn (string $v): string => (string) preg_replace("/'nonce-[^']+'/", "'nonce'", $v), array_values($values));
        }

        return $headers;
    }
}
