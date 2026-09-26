<?php

declare(strict_types=1);

namespace Kinetis\Vector;

use Kinetis\Config\Config;
use Kinetis\Vector\Fields\Currency;

/**
 * Application-wide defaults a resource's fields fall back on:
 * VECTOR_CURRENCY is the currency a Currency field without a default of
 * its own starts from.
 */
final readonly class VectorDefaults
{
    public function __construct(public ?string $currency = null) {}

    public static function fromConfig(Config $config): self
    {
        $currency = strtoupper(trim($config->string('VECTOR_CURRENCY', '')));

        if ($currency !== '' && !in_array($currency, Currency::ISO_4217, true)) {
            throw new \InvalidArgumentException("VECTOR_CURRENCY \"{$currency}\" is not an ISO 4217 currency code.");
        }

        return new self($currency === '' ? null : $currency);
    }
}
