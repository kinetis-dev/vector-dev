<?php

declare(strict_types=1);

namespace Kinetis\Vector\Widgets;

/**
 * A small per-worker memo for widget results: bound on the application
 * scope, so it survives requests by design, and bounded to MAX entries.
 * WidgetRunner builds the key — widget, range and the account it was
 * computed for, or `shared` when the widget declared its result the
 * same for everyone — so nothing here ever answers one account with
 * another's numbers.
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
