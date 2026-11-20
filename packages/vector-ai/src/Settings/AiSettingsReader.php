<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Settings;

use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Store\SettingsStore;

/**
 * The `ai` settings group's values for this request, read once on first
 * use (the defaults where nothing is stored). Request-scoped: the values
 * it keeps end with the request. A job runs in one scope, so work that
 * must see a change saved while it runs — each chunk of a queued action
 * — calls refresh() first.
 */
final class AiSettingsReader
{
    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function __construct(
        private readonly VectorRegistry $registry,
        private readonly SettingsStore $store,
    ) {}

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values ??= $this->store->values($this->registry->settingsGroup('ai'));
    }

    /** Forgets the values read so far: the next read asks the store again. */
    public function refresh(): void
    {
        $this->values = null;
    }

    public function bool(string $name): bool
    {
        return ($this->values()[$name] ?? false) === true;
    }

    public function int(string $name, int $default): int
    {
        $value = $this->values()[$name] ?? null;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    public function string(string $name): string
    {
        $value = $this->values()[$name] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}
