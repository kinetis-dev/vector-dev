<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

final class Number extends Field
{
    public function type(): string
    {
        return 'number';
    }

    /** A decimal with this many fraction digits; without it the number is an integer. */
    public function decimals(int $decimals): static
    {
        return $this->props(['decimals' => $decimals]);
    }

    public function step(float|int $step): static
    {
        return $this->props(['step' => $step]);
    }
}
