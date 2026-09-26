<?php

declare(strict_types=1);

namespace Kinetis\Vector\Testing;

use Kinetis\Testing\TestClient;

/**
 * Drives Vector's REST contract from an application's own test suite,
 * through the framework's TestClient: the application's middleware, its
 * authentication and its policies all run. Immutable; withHeaders()
 * returns a client for another identity.
 *
 *     $vector = new VectorClient($this->client, ['Authorization' => "Bearer {$token}"]);
 *     $vector->resource('products')->list(filter: ['status' => ['eq' => 'draft']])->assertTotal(3);
 */
final readonly class VectorClient
{
    /** @param array<string, string> $headers sent with every request: the identity, a CSRF token */
    public function __construct(
        private TestClient $client,
        private array $headers = [],
        private string $prefix = '/vector',
    ) {}

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->client, [...$this->headers, ...$headers], $this->prefix);
    }

    public function resource(string $slug): ResourceClient
    {
        return new ResourceClient($this->client, $this->headers, "{$this->prefix}/r/{$slug}");
    }

    /** The descriptor, or one resource of it. */
    public function schema(?string $resource = null): VectorResponse
    {
        return new VectorResponse($this->client->get($resource === null ? "{$this->prefix}/schema" : "{$this->prefix}/schema/{$resource}", headers: $this->headers));
    }

    public function me(): VectorResponse
    {
        return new VectorResponse($this->client->get("{$this->prefix}/me", headers: $this->headers));
    }

    public function search(string $term): VectorResponse
    {
        return new VectorResponse($this->client->get("{$this->prefix}/search", ['q' => $term], $this->headers));
    }

    /** The menu's badges, by resource slug. */
    public function badges(): VectorResponse
    {
        return new VectorResponse($this->client->get("{$this->prefix}/badges", [], $this->headers));
    }

    public function job(int $id): VectorResponse
    {
        return new VectorResponse($this->client->get("{$this->prefix}/jobs/{$id}", headers: $this->headers));
    }
}
