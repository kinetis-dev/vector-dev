<?php

declare(strict_types=1);

namespace Kinetis\Vector\Attributes;

use Attribute;

/**
 * Marks a class extending VectorResource as an admin resource over one
 * ORM entity. An entity may have any number of resources, each with its
 * own slug; when it has more than one, exactly one of them is marked
 * `relationTarget`, and a relation to the entity that names no resource
 * of its own (a derived field) links to that one. Everything here is
 * compile-time data: the registry reads it under `kinetis build` (and at
 * boot in development) and never at request time.
 *
 * @param class-string $entity the #[Entity] class this resource administers
 * @param string|null $slug the URL segment; defaults to the entity's table name with underscores replaced by dashes
 * @param string|null $title the field shown as a record's title; defaults to the first string field
 * @param bool $relationTarget whether derived relations to the entity link to this resource; required on exactly one of an entity's resources when it has several
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Resource
{
    public function __construct(
        public string $entity,
        public ?string $slug = null,
        public ?string $label = null,
        public ?string $labelSingular = null,
        public ?string $group = null,
        public ?string $icon = null,
        public ?string $title = null,
        public bool $relationTarget = false,
    ) {}
}
