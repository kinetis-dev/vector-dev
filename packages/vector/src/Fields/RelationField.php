<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use Kinetis\Vector\VectorResource;

/**
 * A field whose value is another resource. `$resource` names the target
 * resource class; the compiler resolves it to a slug and checks that the
 * target administers the entity the relationship points at.
 */
abstract class RelationField extends Field
{
    /** @var class-string<VectorResource>|null */
    protected ?string $resource = null;

    protected ?string $titleField = null;

    /** @param class-string<VectorResource> $resource */
    public static function to(string $name, string $resource, ?string $label = null): static
    {
        $field = static::make($name, $label);
        $field->resource = $resource;

        return $field;
    }

    /** @return class-string<VectorResource>|null */
    public function resource(): ?string
    {
        return $this->resource;
    }

    /** The target's field shown as this relation's label, overriding the target's own title field. */
    public function titleField(string $field): static
    {
        $this->titleField = $field;

        return $this;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'relation' => [
                'kind' => $this->type(),
                'resource' => null,     // the compiler fills the slug in
                'titleField' => $this->titleField,
            ],
        ];
    }
}
