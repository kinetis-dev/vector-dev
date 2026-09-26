<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

/** The identifier: read-only, sortable, never on a form. */
final class Id extends Field
{
    public static function make(string $name = 'id', ?string $label = null): static
    {
        return parent::make($name, $label ?? 'ID')->readonly()->sortable()->hideFromForms();
    }

    public function type(): string
    {
        return 'id';
    }
}
