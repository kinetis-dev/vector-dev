<?php

declare(strict_types=1);

namespace Kinetis\Vector\Mcp;

use Kinetis\Validation\Constraints\In;

/**
 * One `groupBy` entry of vector_aggregate, as its input schema publishes
 * it. The tool binds the list as plain arrays and AggregateRequest
 * validates them; this class only describes their shape.
 */
final readonly class AggregateGroup
{
    public function __construct(
        public string $field,
        #[In(['day', 'week', 'month', 'quarter', 'year'])] public ?string $bucket = null,
    ) {}
}
