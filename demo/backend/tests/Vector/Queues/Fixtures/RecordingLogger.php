<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues\Fixtures;

use Psr\Log\AbstractLogger;

/** Keeps every line logged through it. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $lines = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = ['level' => is_scalar($level) ? (string) $level : 'unknown', 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public function at(string $level): array
    {
        return array_values(array_filter($this->lines, static fn (array $line): bool => $line['level'] === $level));
    }
}
