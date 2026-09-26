<?php

declare(strict_types=1);

namespace App\Vector;

use App\Domain\Category;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\VectorResource;

/** Fully derived from the entity: nothing to write. */
#[Resource(entity: Category::class, group: 'Catalog', icon: 'mdi-shape-outline', title: 'name')]
final class CategoryResource extends VectorResource {}
