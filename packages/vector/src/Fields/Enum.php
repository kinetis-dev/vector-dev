<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use BackedEnum;
use InvalidArgumentException;

final class Enum extends Field
{
    /** @var array<string, string> value => label */
    private array $labels = [];

    /** @var array<string, string> value => Vuetify colour */
    private array $colors = [];

    /** @var class-string<BackedEnum>|null */
    private ?string $enum = null;

    #[\Override]
    public function type(): string
    {
        return 'enum';
    }

    /**
     * The backed enum whose cases are the options. Derivation sets it
     * from the entity's property type; an explicit field names it.
     *
     * @param class-string<BackedEnum> $enum
     */
    public function of(string $enum): static
    {
        if (!is_subclass_of($enum, BackedEnum::class)) {
            throw new InvalidArgumentException("{$enum} is not a backed enum.");
        }

        $this->enum = $enum;

        return $this;
    }

    /** @param array<string, string> $labels value => label */
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

    #[\Override]
    public function toArray(): array
    {
        $options = [];

        if ($this->enum !== null) {
            foreach ($this->enum::cases() as $case) {
                $value = (string) $case->value;
                $options[] = [
                    'value' => $case->value,
                    'label' => $this->labels[$value] ?? self::labelFrom($case->name),
                    'color' => $this->colors[$value] ?? null,
                ];
            }
        }

        return [...parent::toArray(), 'options' => $options, 'enum' => $this->enum];
    }
}
