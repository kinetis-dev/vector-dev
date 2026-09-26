<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

final class Password extends Field
{
    public static function make(string $name, ?string $label = null): static
    {
        return parent::make($name, $label)->onlyOnForms();
    }

    public function type(): string
    {
        return 'password';
    }
}
