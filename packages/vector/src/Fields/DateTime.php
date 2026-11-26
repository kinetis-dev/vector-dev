<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use Kinetis\Vector\Validation\DateBounds;

/** An instant, bounded by minDate() and maxDate() (`now` among them). */
final class DateTime extends Field
{
    use BoundsDates;

    #[\Override]
    public function type(): string
    {
        return 'datetime';
    }

    #[\Override]
    protected function checkBounds(): void
    {
        $this->checkedBounds(DateBounds::DATETIME);
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        return $this->withBounds(parent::toArray(), DateBounds::DATETIME);
    }
}
