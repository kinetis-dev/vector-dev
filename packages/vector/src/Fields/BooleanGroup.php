<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use InvalidArgumentException;

/**
 * A set of named on/off options, shown as one cell (the labels of the
 * ones that are on) and edited as one group of checkboxes. On the wire
 * its value is an object of option key to true or false, every option
 * present; a key a write leaves out is false.
 *
 * Columns mode (the default): every option key names a `bool` property
 * of the entity, each in its own column, so each flag also filters
 * (`filter[group.key]=true`) and sorts (`sort=group.key`) on its own.
 * The field's own name is not a property.
 *
 * JSON mode (json()): the field maps one `array` or `?array` property, a
 * JSON column holding the object of key to bool. Its flags filter with
 * "any" and "all" of a list of keys, never sort.
 *
 * Either way the keys are strict: a write naming another key is refused,
 * and in JSON mode a stored key that is no option throws on read, so
 * removing an option needs a data migration first.
 */
final class BooleanGroup extends Field
{
    public const string COLUMNS = 'columns';
    public const string JSON = 'json';

    /** An option key: a property name in columns mode, and a plain JSON key either way. */
    private const string KEY = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D';

    /** @var array<string, string> key => label */
    private array $options = [];

    private string $storage = self::COLUMNS;

    #[\Override]
    public function type(): string
    {
        return 'booleanGroup';
    }

    #[\Override]
    public function mapsColumn(): bool
    {
        return $this->storage === self::JSON;
    }

    /**
     * The flags, key => label. A key is a name of letters, digits and
     * underscores; in columns mode, the bool property holding the flag.
     *
     * @param array<string, string> $options
     */
    public function options(array $options): static
    {
        foreach ($options as $key => $label) {
            if (preg_match(self::KEY, (string) $key) !== 1) {
                throw new InvalidArgumentException("\"{$this->name}\": the option key \"{$key}\" is not a name of letters, digits and underscores.");
            }

            $this->options[$key] = $label;
        }

        return $this;
    }

    /** Stores the flags as one JSON object in the field's own `array` property. */
    public function json(bool $json = true): static
    {
        $this->storage = $json ? self::JSON : self::COLUMNS;

        return $this;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        $options = [];

        foreach ($this->options as $key => $label) {
            $options[] = ['value' => $key, 'label' => $label, 'color' => null];
        }

        return [...parent::toArray(), 'options' => $options, 'storage' => $this->storage];
    }
}
