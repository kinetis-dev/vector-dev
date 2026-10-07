<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

/**
 * A JSON object of text values in a string column — attributes, labels,
 * settings — edited as key/value rows rather than as JSON text. On the
 * wire it is an object; a stored number or boolean reads back as text.
 */
final class KeyValue extends Field
{
    public const int KEY_LENGTH = 100;

    #[\Override]
    public function type(): string
    {
        return 'keyValue';
    }

    /** How many entries a value may hold (50 by default). */
    public function maxEntries(int $entries): static
    {
        return $this->props(['maxEntries' => max(1, $entries)]);
    }

    /** What the input calls the two columns, "Key" and "Value" by default. */
    public function columns(string $key, string $value): static
    {
        return $this->props(['keyLabel' => $key, 'valueLabel' => $value]);
    }
}
