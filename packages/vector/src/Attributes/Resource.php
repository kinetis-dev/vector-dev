<?php

declare(strict_types=1);

namespace Kinetis\Vector\Attributes;

use Attribute;

/**
 * Marks a class extending VectorResource as the admin resource for one
 * ORM entity. Everything here is compile-time data: the registry reads
 * it under `kinetis build` (and at boot in development) and never at
 * request time.
 *
 * @param class-string $entity the #[Entity] class this resource administers
 * @param string|null $slug the URL segment; defaults to the entity's table name
 * @param string|null $title the field shown as a record's title; defaults to the first string field
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
    ) {}
}
