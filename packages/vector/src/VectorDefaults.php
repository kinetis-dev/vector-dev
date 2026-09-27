<?php

declare(strict_types=1);

namespace Kinetis\Vector;

use Kinetis\Config\Config;
use Kinetis\Vector\Fields\Currency;
use Kinetis\Vector\Query\XlsxWriter;

/**
 * Application-wide defaults a resource's fields fall back on:
 * VECTOR_CURRENCY is the currency a Currency field without a default of
 * its own starts from. VECTOR_EXPORT_FORMATS lists the export formats
 * offered (`csv,xlsx` by default); CSV is always among them, and XLSX
 * only where openspout/openspout and ext-zip are installed.
 */
final readonly class VectorDefaults
{
    /** @param list<'csv'|'xlsx'> $exportFormats */
    public function __construct(public ?string $currency = null, public array $exportFormats = ['csv']) {}

    public static function fromConfig(Config $config): self
    {
        $currency = strtoupper(trim($config->string('VECTOR_CURRENCY', '')));

        if ($currency !== '' && !in_array($currency, Currency::ISO_4217, true)) {
            throw new \InvalidArgumentException("VECTOR_CURRENCY \"{$currency}\" is not an ISO 4217 currency code.");
        }

        $formats = array_filter(array_map('trim', explode(',', strtolower($config->string('VECTOR_EXPORT_FORMATS', 'csv,xlsx')))));

        foreach ($formats as $format) {
            if (!in_array($format, ['csv', 'xlsx'], true)) {
                throw new \InvalidArgumentException("VECTOR_EXPORT_FORMATS names \"{$format}\"; the formats are csv and xlsx.");
            }
        }

        return new self($currency === '' ? null : $currency, in_array('xlsx', $formats, true) && XlsxWriter::available() ? ['csv', 'xlsx'] : ['csv']);
    }
}
