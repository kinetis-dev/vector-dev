<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use Kinetis\Validation\Constraints\Email as EmailConstraint;
use Kinetis\Validation\Constraints\Regex;

/** An e-mail address, validated as one unless the field declares an e-mail or pattern rule of its own. */
final class Email extends Field
{
    public function type(): string
    {
        return 'email';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $own = array_any($this->rules, static fn (array $rule): bool => in_array($rule[0], [EmailConstraint::class, Regex::class], true));

        if (!$own) {
            $this->rules[] = [EmailConstraint::class, []];
        }

        return parent::toArray();
    }
}
