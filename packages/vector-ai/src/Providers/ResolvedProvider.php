<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Providers;

use NeuronAI\Providers\AIProviderInterface;

/** A provider built for one call: the Neuron provider, the configured name it came from, its model and its output cap. */
final readonly class ResolvedProvider
{
    public function __construct(
        public AIProviderInterface $provider,
        public string $name,
        public string $model,
        public int $maxTokens,
    ) {}
}
