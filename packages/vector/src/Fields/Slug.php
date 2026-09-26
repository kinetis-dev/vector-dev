<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

final class Slug extends Field
{
    public function type(): string
    {
        return 'slug';
    }

    public function from(string $field): static
    {
        return $this->props(['from' => $field]);
    }
}
