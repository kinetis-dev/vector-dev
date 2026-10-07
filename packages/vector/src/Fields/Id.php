<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

/** The identifier: read-only, sortable, never on a form. */
final class Id extends Field
{
    #[\Override]
    public static function make(string $name = 'id', ?string $label = null): static
    {
        return parent::make($name, $label ?? 'ID')->readonly()->sortable()->hideFromForms();
    }

    #[\Override]
    public function type(): string
    {
        return 'id';
    }
}
