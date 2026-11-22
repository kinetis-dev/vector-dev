<?php

declare(strict_types=1);

namespace Kinetis\VectorAi;

use Kinetis\AwsSigV4\SignedTransport;
use Kinetis\AwsSigV4\SigV4SigningClient;
use Kinetis\Neuron\NeuronHttpClient;
use Kinetis\RevoltHttpClient\Http;
use Kinetis\VectorAi\Config\AiConfig;
use Kinetis\VectorAi\Config\ProviderConfig;
use Kinetis\VectorAi\Config\ProviderType;

/**
 * The transports every provider call goes through, built once at boot and
 * shared by every request of a worker: one NeuronHttpClient per HTTP
 * provider origin, pinned to that base URL, under VECTOR_AI_TIMEOUT and
 * VECTOR_AI_MAX_RESPONSE_BYTES; one SigV4SigningClient per Bedrock region,
 * on the default AWS credential chain. Both are immutable and hold no
 * request data. The providers built over them are not: ProviderResolver
 * builds a new one for every call.
 */
final readonly class AiTransports
{
    /**
     * @param array<string, NeuronHttpClient> $http base URL => client
     * @param array<string, SigV4SigningClient> $bedrock region => signing client
     */
    public function __construct(private array $http = [], private array $bedrock = []) {}

    public static function fromConfig(AiConfig $config): self
    {
        $http = [];
        $bedrock = [];
        $base = new Http()->withTimeout($config->timeout)->withMaxResponseBytes($config->maxResponseBytes);

        foreach ($config->providers as $provider) {
            if ($provider->type->overHttp() && $provider->baseUrl !== null) {
                $http[$provider->baseUrl] ??= new NeuronHttpClient($base, $provider->baseUrl);
            } elseif ($provider->type === ProviderType::Bedrock && $provider->region !== null) {
                $bedrock[$provider->region] ??= new SigV4SigningClient(
                    origin: "https://bedrock-runtime.{$provider->region}.amazonaws.com",
                    region: $provider->region,
                    service: 'bedrock',
                    transport: SignedTransport::create(['timeout' => $config->timeout, 'max_duration' => $config->timeout]),
                );
            }
        }

        return new self($http, $bedrock);
    }

    /** @throws \LogicException when the provider has no HTTP transport: it was not configured at boot */
    public function http(ProviderConfig $provider): NeuronHttpClient
    {
        return $this->http[(string) $provider->baseUrl] ?? throw new \LogicException("No HTTP transport was built for the AI provider \"{$provider->name}\".");
    }

    /** @throws \LogicException when the provider has no signing client: it was not configured at boot */
    public function bedrock(ProviderConfig $provider): SigV4SigningClient
    {
        return $this->bedrock[(string) $provider->region] ?? throw new \LogicException("No Bedrock signing client was built for the AI provider \"{$provider->name}\".");
    }
}
