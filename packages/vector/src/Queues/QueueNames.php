<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues;

use Kinetis\Config\Config;

/**
 * The queue names the dashboard watches, and the only ones it accepts in
 * a path or a filter: `VECTOR_QUEUES`, a comma-separated list, in the
 * order the overview lists them. Unset or blank, it is `default` alone —
 * the names a worker serves are its own `--queue` argument, which no
 * HTTP process can see. Read once per worker, at boot; a name outside
 * kinetis/queue's grammar stops the boot rather than a request.
 */
final readonly class QueueNames
{
    /** kinetis/queue's queue-name grammar (QueueContract), as Vector's own VectorQueue checks VECTOR_QUEUE. */
    private const string QUEUE_NAME = '/^[A-Za-z0-9_-]{1,80}$/D';

    /** @param non-empty-list<string> $names */
    private function __construct(public array $names) {}

    public static function fromConfig(Config $config): self
    {
        $names = array_values(array_unique(array_filter(array_map(trim(...), explode(',', $config->string('VECTOR_QUEUES', ''))), static fn (string $name): bool => $name !== '')));

        foreach ($names as $name) {
            if (!self::isQueueName($name)) {
                throw new \InvalidArgumentException("VECTOR_QUEUES names \"{$name}\", which is not a queue name: letters, digits, hyphens and underscores, up to 80 characters.");
            }
        }

        return new self($names === [] ? ['default'] : $names);
    }

    /** Whether $name is in kinetis/queue's grammar, whatever this dashboard watches. */
    public static function isQueueName(string $name): bool
    {
        return preg_match(self::QUEUE_NAME, $name) === 1;
    }

    public function has(string $name): bool
    {
        return in_array($name, $this->names, true);
    }
}
