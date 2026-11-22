<?php

declare(strict_types=1);

namespace Kinetis\Vector\Mcp;

use Kinetis\Validation\Constraints\In;

/**
 * One `metrics` entry of vector_aggregate, as its input schema publishes
 * it. The tool binds the list as plain arrays and AggregateRequest
 * validates them; this class only describes their shape.
 */
final readonly class AggregateMetric
{
    public function __construct(
        #[In(['count', 'sum', 'avg', 'min', 'max'])] public string $fn,
        public ?string $field = null,
    ) {}
}
