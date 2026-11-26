<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use Kinetis\Vector\Support\Countries;

/**
 * A country as its ISO 3166-1 alpha-2 code (`DE`, `PT`): a select over
 * every assigned code, searchable by default, one code in a string
 * property or several with multiple() in an `array` property. only()
 * narrows the list; the build refuses a code that is not assigned. The
 * server names each code in English; the admin shows the name in the
 * UI's language.
 */
final class Country extends Select
{
    #[\Override]
    public static function make(string $name, ?string $label = null): static
    {
        return parent::make($name, $label)->options(Countries::NAMES)->searchable();
    }

    #[\Override]
    public function type(): string
    {
        return 'country';
    }

    /**
     * The codes the list offers, in the order given. An unassigned code
     * is kept for the build to refuse with the resource it belongs to.
     *
     * @param list<string> $codes
     */
    public function only(array $codes): static
    {
        $options = [];

        foreach ($codes as $code) {
            $options[$code] = Countries::name($code) ?? $code;
        }

        return $this->options($options);
    }
}
