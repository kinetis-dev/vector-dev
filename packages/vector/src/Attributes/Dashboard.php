<?php

declare(strict_types=1);

namespace Kinetis\Vector\Attributes;

use Attribute;

/** Marks a VectorDashboard subclass for discovery, like #[Resource] and #[Settings]. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Dashboard
{
    public function __construct()
    {
        // a marker: the class carries it for discovery, and it takes no arguments
    }
}
