<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use BackedEnum;
use Closure;
use InvalidArgumentException;

/**
 * A choice among fixed options: one value, or several with multiple().
 *
 * The options come from an array (`value => label`), from a closure
 * returning one, or from a backed enum (its cases, labelled from their
 * names unless labels() renames them). A closure runs once, when the
 * resource compiles, never per request.
 *
 * One value lives in a string, int or backed enum property; several live
 * in an `array` property, a JSON column holding a list of option values,
 * deduplicated and kept in the order the options are declared. The
 * compiler checks that storage against the mode and refuses a mismatch.
 * A value outside the options is refused on write and throws on read, so
 * removing an option needs a data migration first.
 */
class Select extends Field
{
    /** @var array<int|string, string>|Closure|class-string<BackedEnum>|null */
    private array|Closure|string|null $source = null;

    /** @var array<string, string> value => label */
    private array $labels = [];

    /** @var array<string, string> value => colour */
    private array $colors = [];

    private bool $multiple = false;

    private bool $autocomplete = false;

    private bool $badge = false;

    #[\Override]
    public function type(): string
    {
        return 'select';
    }

    #[\Override]
    protected function hasTextBox(): bool
    {
        return true;
    }

    /**
     * The choices: an array of value => label, a closure returning one,
     * or a backed enum class.
     *
     * @param array<int|string, string>|Closure(): array<int|string, string>|class-string<BackedEnum> $options
     */
    public function options(array|Closure|string $options): static
    {
        if (is_string($options)) {
            return $this->of($options);
        }

        $this->source = $options;

        return $this;
    }

    /**
     * The backed enum whose cases are the options. Derivation sets it
     * from the entity's property type; an explicit field may name it.
     *
     * @param class-string<BackedEnum> $enum
     */
    public function of(string $enum): static
    {
        $this->source = self::backedEnum($enum);

        return $this;
    }

    /**
     * The class of() accepts: a backed enum, or a refusal.
     *
     * @return class-string<BackedEnum>
     */
    private static function backedEnum(string $enum): string
    {
        if (!is_subclass_of($enum, BackedEnum::class)) {
            throw new InvalidArgumentException("{$enum} is not a backed enum.");
        }

        return $enum;
    }

    /** Whether the options come from somewhere yet: the compiler fills them from an enum property otherwise. */
    public function hasOptions(): bool
    {
        return $this->source !== null;
    }

    /** @param array<string, string> $labels value => label, overriding the declared or derived ones */
    public function labels(array $labels): static
    {
        $this->labels = [...$this->labels, ...$labels];

        return $this;
    }

    /** @param array<string, string> $colors value => colour name ('success', 'warning', ...) */
    public function colors(array $colors): static
    {
        $this->colors = [...$this->colors, ...$colors];

        return $this;
    }

    /** Several values at once, stored as a JSON list in an `array` property. */
    public function multiple(bool $multiple = true): static
    {
        $this->multiple = $multiple;

        return $this;
    }

    /**
     * The input offers a search box over the options, for a long list.
     * Unlike other fields, a select's searchable() does not make the
     * list's search query match its column.
     */
    #[\Override]
    public function searchable(bool $searchable = true): static
    {
        $this->autocomplete = $searchable;

        return $this;
    }

    /**
     * Shows each value as a coloured chip in lists and on the record,
     * coloured by $colors (value => colour), merged with colors().
     *
     * @param array<string, string> $colors
     */
    public function badge(array $colors = []): static
    {
        $this->badge = true;

        return $this->colors($colors);
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        $enum = is_string($this->source) ? $this->source : null;

        return [
            ...parent::toArray(),
            'options' => $this->resolveOptions(),
            'enum' => $enum,
            'multiple' => $this->multiple,
            'autocomplete' => $this->autocomplete,
            'badge' => $this->badge,
        ];
    }

    /** @return list<array{value: int|string, label: string, color: string|null}> */
    private function resolveOptions(): array
    {
        $source = $this->source instanceof Closure ? ($this->source)() : $this->source;

        if ($source === null) {
            return [];
        }

        if (is_string($source)) {
            $options = [];

            // a closure naming the enum is held to what of() accepts
            foreach (self::backedEnum($source)::cases() as $case) {
                $options[] = $this->option($case->value, self::labelFrom($case->name));
            }

            return $options;
        }

        if (!is_array($source)) {
            throw new InvalidArgumentException("\"{$this->name}\": the options closure must return an array of value => label.");
        }

        $options = [];

        foreach ($source as $value => $label) {
            if (!is_string($label) && !is_int($label)) {
                throw new InvalidArgumentException("\"{$this->name}\": the option \"{$value}\" has a label that is not text.");
            }

            $options[] = $this->option($value, (string) $label);
        }

        return $options;
    }

    /** @return array{value: int|string, label: string, color: string|null} */
    private function option(int|string $value, string $label): array
    {
        $key = (string) $value;

        return ['value' => $value, 'label' => $this->labels[$key] ?? $label, 'color' => $this->colors[$key] ?? null];
    }
}
