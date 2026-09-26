<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

final class BelongsTo extends RelationField
{
    /** @var array{field: string, on: string}|null */
    private ?array $dependsOn = null;

    public function type(): string
    {
        return 'belongsTo';
    }

    /**
     * Narrows the choices to the target records whose `$on` (by default
     * the same name) equals this form's `$field`: a ticket's assignee
     * among the agents of the ticket's team. The options endpoint filters
     * by it, and a write that changes either side is checked against it.
     */
    public function dependsOn(string $field, ?string $on = null): static
    {
        $this->dependsOn = ['field' => $field, 'on' => $on ?? $field];

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $array = parent::toArray();
        $array['relation']['dependsOn'] = $this->dependsOn;

        return $array;
    }
}
