<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use Kinetis\Validation\Constraints\Regex;
use Kinetis\Validation\Constraints\Url as UrlConstraint;
use Kinetis\Vector\Validation\WebUrl;

/**
 * An absolute http(s) URL, shown as a link: validated as such unless the
 * field declares a URL or pattern rule of its own.
 */
final class Url extends Field
{
    #[\Override]
    public function type(): string
    {
        return 'url';
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        $own = array_any($this->rules, static fn (array $rule): bool => in_array($rule[0], [WebUrl::class, UrlConstraint::class, Regex::class], true));

        if (!$own) {
            $this->rules[] = [WebUrl::class, []];
        }

        return parent::toArray();
    }
}
