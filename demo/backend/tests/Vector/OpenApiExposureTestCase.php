<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Testing\TestResponse;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;

/**
 * `GET /vector/openapi.json` is served exactly where the framework
 * serves its own `/openapi.json`: OPENAPI_ENVIRONMENTS naming APP_ENV.
 * Each subclass boots under one setting. Where it is closed, the path
 * answers what a path no route matches answers, byte for byte, for
 * every method and with or without credentials — so never a 401.
 *
 * The Router compares the request path, trimmed of its leading and
 * trailing slashes, against the route's literal template: case-sensitive,
 * with no percent-decoding, no collapsing of inner slashes and no dot
 * segments resolved. So of the spellings below only the slash-trimmed
 * ones reach the controller; the gate must close exactly those.
 */
abstract class OpenApiExposureTestCase extends VectorTestCase
{
    private const array METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /** @var array<string, bool> each spelling of the path, and whether the Router routes it to the controller */
    private const array VARIANTS = [
        '/vector/openapi.json' => true,
        '/vector/openapi.json/' => true,
        '//vector/openapi.json' => true,
        '/VECTOR/openapi.json' => false,
        '/vector/OpenAPI.json' => false,
        '/vector//openapi.json' => false,
        '/vector/openapi%2Ejson' => false,
        '/vector/openapi%2ejson' => false,
        '/vector/%6Fpenapi.json' => false,
        '/vector/./openapi.json' => false,
    ];

    /** The OPENAPI_ENVIRONMENTS this case boots with; null boots without the key at all. */
    abstract protected function environments(): ?string;

    abstract protected function served(): bool;

    protected function configOverrides(): array
    {
        $environments = $this->environments();

        return [
            ...parent::configOverrides(),
            'APP_ENV' => 'development',
            ...($environments === null ? [] : ['OPENAPI_ENVIRONMENTS' => $environments]),
        ];
    }

    /**
     * Without the key: the configuration the application boots with is
     * replaced, before the Kernel and its global middleware are built
     * from it, by one holding the same values but OPENAPI_ENVIRONMENTS,
     * which `.env` would otherwise have put back.
     */
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);

        if ($this->environments() === null) {
            /** @var array<string, string> $values */
            $values = [...getenv(), ...$this->configOverrides()];
            unset($values['OPENAPI_ENVIRONMENTS']);
            $app->instance(Config::class, new Config($values));
        }
    }

    public function testTheEndpointFollowsTheFrameworksOwnDocument(): void
    {
        $framework = $this->client->get('/openapi.json');

        if (!$this->served()) {
            $framework->assertStatus(404);

            foreach (self::METHODS as $method) {
                foreach ([[], ['Authorization' => 'Bearer not-a-token'], $this->adminHeaders()] as $headers) {
                    foreach (array_keys(self::VARIANTS) as $path) {
                        $this->assertUnrouted($method, $path, $headers);
                    }
                }
            }

            return;
        }

        $framework->assertOk();

        foreach (self::VARIANTS as $path => $routed) {
            if (!$routed) {
                $this->assertUnrouted('GET', $path, []);
                $this->assertUnrouted('GET', $path, $this->adminHeaders());

                continue;
            }

            $document = $this->at('GET', $path, $this->adminHeaders())->assertOk()->json();
            self::assertIsArray($document, $path);
            self::assertSame('3.1.0', $document['openapi'], $path);
            self::assertNotEmpty($document['paths'], $path);

            // open is not public: the vector group still stands in front of it
            $this->at('GET', $path, [])->assertStatus(401);
            $this->at('GET', $path, $this->editorHeaders())->assertStatus(403);
            $this->at('POST', $path, $this->adminHeaders())->assertStatus(405);
        }
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
        $expected = $this->at($method, $unknown, $headers);
        $actual = $this->at($method, $path, $headers);
        $label = "{$method} {$path} " . ($headers === [] ? 'anonymous' : 'with credentials');

        self::assertSame(404, $actual->getStatusCode(), $label);
        self::assertSame(self::headers($expected), self::headers($actual), $label);
        self::assertSame(
            // the message names the path as the request reports it (a PSR-7 path without an
            // authority has one leading slash), with its slashes escaped or not
            str_replace([$unknown, addcslashes($unknown, '/')], [$seen = (new Uri())->withPath($path)->getPath(), addcslashes($seen, '/')], $expected->body()),
            $actual->body(),
            $label,
        );
        self::assertStringContainsString('No route matches path', $actual->body(), $label);
    }

    /**
     * $method on exactly $path. A runtime sets the request path to the
     * request target's own (nyholm/psr7-server's ServerRequestCreator:
     * `withPath()` of REQUEST_URI before its `?`), where a URI string
     * would read `//vector/…` as a host.
     *
     * @param array<string, string> $headers
     */
    private function at(string $method, string $path, array $headers): TestResponse
    {
        return $this->client->send(new ServerRequest($method, (new Uri())->withPath($path), $headers));
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
