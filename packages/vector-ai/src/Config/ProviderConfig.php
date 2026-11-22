<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Config;

/**
 * One provider an operator configured, as read once at boot. Immutable
 * and request-neutral. It may hold the API key the environment gives
 * (VECTOR_AI_<NAME>_KEY): never log it, never send it to a client.
 */
final readonly class ProviderConfig
{
    /**
     * @param list<string> $models the allow-list; empty admits any model
     */
    public function __construct(
        public string $name,
        public ProviderType $type,
        public ?string $baseUrl,
        #[\SensitiveParameter]
        public ?string $key,
        public array $models,
        public ?string $region,
    ) {}

    /** Whether the environment gives this provider its key, so an administrator cannot store one. */
    public function keyFromEnvironment(): bool
    {
        return $this->key !== null;
    }

    /** Whether $model is on the allow-list, or the list is empty. */
    public function allows(string $model): bool
    {
        return $this->models === [] || in_array($model, $this->models, true);
    }

    /** @return array<string, mixed> without the key */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'type' => $this->type->value, 'baseUrl' => $this->baseUrl, 'key' => $this->key === null ? null : '(set)', 'models' => $this->models, 'region' => $this->region];
    }
}
