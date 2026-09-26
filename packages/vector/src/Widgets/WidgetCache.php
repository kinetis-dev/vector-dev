<?php

declare(strict_types=1);

namespace Kinetis\Vector\Widgets;

/**
 * A small per-worker memo for widget results: bound on the application
 * scope, so it survives requests by design, bounded to MAX entries and
 * keyed by widget and range only — never by user — so a cached result is
 * one every admitted admin may see. A widget that depends on the user
 * declares `cacheSeconds()` 0.
 */
final class WidgetCache
{
    private const int MAX = 200;

    /** @var array<string, array{until: float, result: array<string, mixed>}> */
    private array $entries = [];

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['until'] < microtime(true)) {
            unset($this->entries[$key]);

            return null;
        }

        return $entry['result'];
    }

    /** @param array<string, mixed> $result */
    public function put(string $key, array $result, int $seconds): void
    {
        if (count($this->entries) >= self::MAX) {
            $this->entries = array_slice($this->entries, (int) (self::MAX / 2), null, true);
        }

        $this->entries[$key] = ['until' => microtime(true) + $seconds, 'result' => $result];
    }
}
