<?php

declare(strict_types=1);

namespace Kinetis\Vector\Validation;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;

/**
 * An absolute http(s) URL: what a `Url` field holds, since the admin
 * shows the value as a link. PHP's own URL filter admits `javascript:`
 * and `file:` targets, so the scheme is checked here.
 */
final readonly class WebUrl implements Constraint
{
    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!is_string($value) || preg_match('~^https?://[^\s/?#]+[^\s]*$~iD', $value) !== 1 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return new Violation([], 'url', 'must be an http or https URL.');
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return ['format' => 'uri'];
    }
}
