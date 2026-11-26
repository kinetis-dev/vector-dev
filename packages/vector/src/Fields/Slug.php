<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

final class Slug extends Field
{
    #[\Override]
    public function type(): string
    {
        return 'slug';
    }

    #[\Override]
    protected function hasTextBox(): bool
    {
        return true;
    }

    public function from(string $field): static
    {
        return $this->props(['from' => $field]);
    }
}
