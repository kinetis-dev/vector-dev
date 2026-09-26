<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

/**
 * An ISO 4217 currency code in a string column, chosen from a list: every
 * active code, or the ones allowed(). Stored upper-case; anything else is
 * refused, so a money field priced in it always formats. Its default is
 * default('EUR'), or the application's VECTOR_CURRENCY when it sets none.
 */
final class Currency extends Field
{
    /** The active ISO 4217 codes, and a few recently replaced ones stored data may still hold. */
    public const array ISO_4217 = [
        'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF',
        'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP', 'CRC',
        'CUP', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS',
        'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'IQD', 'IRR', 'ISK', 'JMD',
        'JOD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KPW', 'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL',
        'LYD', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD',
        'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD',
        'RUB', 'RWF', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SSP', 'STN', 'SVC', 'SYP',
        'SZL', 'THB', 'TJS', 'TMT', 'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'VES',
        'VND', 'VUV', 'WST', 'XAF', 'XCD', 'XCG', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW', 'ZWG',
    ];

    /**
     * The ISO 4217 minor-unit exponent of every code whose minor unit is
     * not a hundredth: a money value in yen is whole yen, one in dinars
     * is thousandths. Every other code has two decimals.
     */
    public const array MINOR_UNITS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0, 'PYG' => 0,
        'RWF' => 0, 'UGX' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    /** @var list<string>|null */
    private ?array $allowed = null;

    /** How many decimals a money value in this currency has: 2, or its ISO 4217 exception. */
    public static function minorUnits(string $code): int
    {
        return self::MINOR_UNITS[strtoupper($code)] ?? 2;
    }

    public function type(): string
    {
        return 'currency';
    }

    /**
     * The codes a value may take, in the order the list offers them.
     *
     * @param list<string> $codes
     */
    public function allowed(array $codes): static
    {
        $this->allowed = array_values(array_unique(array_map(static fn (string $code): string => strtoupper(trim($code)), $codes)));

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            // the choices, as an enum publishes its cases; the client names each in its own language
            'options' => array_map(static fn (string $code): array => ['value' => $code, 'label' => $code, 'color' => null], $this->allowed ?? self::ISO_4217),
        ];
    }
}
