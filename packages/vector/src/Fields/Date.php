<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use Kinetis\Vector\Validation\DateBounds;

/** A calendar day, bounded by minDate() and maxDate() (`today` among them). */
final class Date extends Field
{
    use BoundsDates;

    #[\Override]
    public function type(): string
    {
        return 'date';
    }

    #[\Override]
    protected function checkBounds(): void
    {
        $this->checkedBounds(DateBounds::DATE);
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        return $this->withBounds(parent::toArray(), DateBounds::DATE);
    }
}
