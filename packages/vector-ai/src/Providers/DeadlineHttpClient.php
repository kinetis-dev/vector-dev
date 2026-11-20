<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Providers;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/**
 * A feature's own deadline over the shared transport: every request a
 * provider sends through it carries $seconds as its timeout, unless the
 * request already asks for a shorter one. That timeout replaces the
 * transport's own bound (VECTOR_AI_TIMEOUT) rather than narrowing it, so
 * $seconds must already be no longer than that bound: ProviderResolver
 * passes the shorter of the two. Holds no request data, and is built with
 * the provider, for one call.
 */
final readonly class DeadlineHttpClient implements HttpClientInterface
{
    public function __construct(private HttpClientInterface $inner, private float $seconds) {}

    #[\Override]
    public function request(HttpRequest $request): HttpResponse
    {
        return $this->inner->request($this->bounded($request));
    }

    #[\Override]
    public function stream(HttpRequest $request): StreamInterface
    {
        return $this->inner->stream($this->bounded($request));
    }

    private function bounded(HttpRequest $request): HttpRequest
    {
        $timeout = $request->timeout === null ? $this->seconds : min($request->timeout, $this->seconds);

        return new HttpRequest($request->method, $request->uri, $request->headers, $request->body, $timeout);
    }
}
