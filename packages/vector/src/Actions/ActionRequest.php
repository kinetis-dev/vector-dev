<?php

declare(strict_types=1);

namespace Kinetis\Vector\Actions;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\Orm\EntityManager;
use Kinetis\Vector\Registry\ResourceDescriptor;

/**
 * What an action handles: the records it was run on, both as the
 * entities the ORM loaded (mutate them; the runner flushes once) and as
 * the serialized rows the policy saw, plus the dialog's converted
 * values. Empty lists for a standalone action.
 */
final readonly class ActionRequest
{
    /**
     * @param list<object> $entities
     * @param list<array<string, mixed>> $records
     * @param array<string, mixed> $fields field name => converted value
     */
    public function __construct(
        public ResourceDescriptor $resource,
        public CurrentUserInterface $user,
        public EntityManager $manager,
        public array $entities,
        public array $records,
        public array $fields,
    ) {}

    /** @return list<object> */
    public function entities(): array
    {
        return $this->entities;
    }

    public function field(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->fields) ? $this->fields[$name] : $default;
    }

    public function count(): int
    {
        return count($this->entities);
    }
}
