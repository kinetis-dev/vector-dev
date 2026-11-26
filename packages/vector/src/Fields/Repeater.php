<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use Kinetis\Vector\VectorResource;

/**
 * Rows edited inside the record's form, in one of two modes.
 *
 * JSON mode (the default): `fields()` declares the fields of a row, and
 * the rows are stored as a JSON list of objects in one `array` or
 * `?array` property. Each row is validated and converted by the fields it
 * declares; a key no field declares is refused. The list's order is the
 * order the rows were sent in.
 *
 * Relationship mode: `relationship('lines')` names an owned has-many
 * relation of the entity, and each row is one child record, written with
 * the parent in one transaction: a row with an `id` updates that child,
 * one without creates it, and a child left out is deleted. `fields()`
 * then names the child resource's fields a row edits (every one it can
 * write by default). `orderBy('position')` names an int property of the
 * child that receives each row's index on save and orders the rows.
 *
 * `min()` and `max()` bound the number of rows in both modes. A repeater
 * is not sortable, groupable, summarized or edited inline, and rows never
 * hold another repeater.
 */
final class Repeater extends Field
{
    /** A JSON-mode repeater's row fields are limited to these types: plain values a JSON document holds. */
    public const array ROW_TYPES = ['string', 'text', 'markdown', 'email', 'url', 'slug', 'color', 'number', 'money', 'boolean', 'select', 'currency', 'date', 'datetime', 'tag'];

    /** @var list<Field|string> */
    private array $rowFields = [];

    private bool $declared = false;

    private ?string $relationship = null;

    /** @var class-string<VectorResource>|null */
    private ?string $resource = null;

    private int $minRows = 0;

    private int $maxRows = 100;

    private ?string $orderBy = null;

    #[\Override]
    public function type(): string
    {
        return 'repeater';
    }

    /** A relationship-mode repeater writes child records, not a column of its own. */
    #[\Override]
    public function mapsColumn(): bool
    {
        return $this->relationship === null;
    }

    /**
     * The fields of a row: field definitions in JSON mode, the child
     * resource's field names in relationship mode.
     *
     * @param array<array-key, Field|string> $fields
     */
    public function fields(array $fields): static
    {
        $this->rowFields = array_values($fields);
        $this->declared = true;

        return $this;
    }

    /**
     * Edits an owned has-many relation of the entity instead of a JSON
     * column: `$relation` names it (the field's own name by default), and
     * `$resource` the child resource, which otherwise is the one resource
     * administering the child entity, or the one marked relationTarget.
     *
     * @param class-string<VectorResource>|null $resource
     */
    public function relationship(?string $relation = null, ?string $resource = null): static
    {
        $this->relationship = $relation ?? $this->name;
        $this->resource = $resource;

        return $this;
    }

    /** The fewest rows the list may hold: `$length` counts rows here, not characters. */
    #[\Override]
    public function min(int $length): static
    {
        $this->minRows = max(0, $length);

        return $this;
    }

    /** The most rows the list may hold: `$length` counts rows here, not characters. */
    #[\Override]
    public function max(int $length): static
    {
        $this->maxRows = max(1, $length);

        return $this;
    }

    /**
     * Lets a relationship-mode repeater's rows be reordered by dragging:
     * `$property` names an int property of the child entity that receives
     * each row's index (from 0) on save, and the rows load ordered by it.
     * A JSON-mode repeater needs none: its list is stored in the order it
     * is sent, so its rows are always reordered by dragging, and the
     * compiler refuses orderBy() there.
     */
    public function orderBy(string $property): static
    {
        $this->orderBy = $property;

        return $this;
    }

    /** @return list<Field|string> */
    public function rowFields(): array
    {
        return $this->rowFields;
    }

    /** Whether fields() was called: a relationship-mode repeater otherwise edits every writable child field. */
    public function declaresFields(): bool
    {
        return $this->declared;
    }

    /** The has-many relation a relationship-mode repeater edits, or null in JSON mode. */
    public function relationName(): ?string
    {
        return $this->relationship;
    }

    /** @return class-string<VectorResource>|null */
    public function childResource(): ?string
    {
        return $this->resource;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'repeater' => [
                'mode' => $this->relationship === null ? 'json' : 'relationship',
                'fields' => array_map(static fn (Field|string $f): string => is_string($f) ? $f : $f->name(), $this->rowFields),
                'min' => $this->minRows,
                'max' => $this->maxRows,
                // whether rows reorder by dragging: always for a JSON list, by orderBy() for child rows
                'sortable' => $this->relationship === null || $this->orderBy !== null,
                'orderBy' => $this->orderBy,
            ],
        ];
    }
}
