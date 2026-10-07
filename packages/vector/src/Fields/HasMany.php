<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

final class HasMany extends RelationField
{
    /** @var array{fields: list<string>|null, min: int, max: int}|null */
    private ?array $repeater = null;

    #[\Override]
    public static function to(string $name, string $resource, ?string $label = null): static
    {
        return parent::to($name, $resource, $label)->hideFromForms();
    }

    #[\Override]
    public function type(): string
    {
        return 'hasMany';
    }

    #[\Override]
    public function mapsColumn(): bool
    {
        return false;
    }

    /**
     * Edits an owned relation's rows inside the parent's form: the whole
     * list is sent with the parent and written in its transaction — a
     * row with an `id` updates that child, one without creates it, and a
     * child left out is deleted. `$fields` picks the child's fields a row
     * shows (every one it can write by default, never the one pointing
     * back at the parent).
     *
     * @param list<string>|null $fields
     */
    public function repeater(?array $fields = null, int $min = 0, int $max = 100): static
    {
        $this->repeater = ['fields' => $fields, 'min' => max(0, $min), 'max' => max(1, $max)];
        $this->views = array_values(array_unique([...$this->views, self::VIEW_CREATE, self::VIEW_UPDATE]));

        return $this;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        return [...parent::toArray(), 'repeater' => $this->repeater];
    }
}
