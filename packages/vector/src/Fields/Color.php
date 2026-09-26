<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use Kinetis\Validation\Constraints\Regex;

/** A colour as `#RRGGBB`, validated as such unless the field declares a pattern of its own. */
final class Color extends Field
{
    public const string PATTERN = '/^#[0-9A-Fa-f]{6}$/D';

    public function type(): string
    {
        return 'color';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $patterned = array_any($this->rules, static fn (array $rule): bool => $rule[0] === Regex::class);

        if (!$patterned) {
            $this->rules[] = [Regex::class, [self::PATTERN]];
        }

        return parent::toArray();
    }
}
