<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

final class Money extends Field
{
    public function type(): string
    {
        return 'money';
    }

    /** The value is stored in minor units (cents) of this currency, or of the named currency field. */
    public function currency(string $currency): static
    {
        return $this->props(['currency' => $currency]);
    }

    /**
     * The field holding the currency: one of this record's (`currency`),
     * or one of a related record's through a belongsTo field
     * (`order.currency` — a line priced in its order's currency).
     */
    public function currencyField(string $field): static
    {
        return $this->props(['currencyField' => $field]);
    }
}
