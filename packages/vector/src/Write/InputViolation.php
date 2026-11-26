<?php

declare(strict_types=1);

namespace Kinetis\Vector\Write;

use Exception;
use Kinetis\Validation\Violation;

/**
 * Carries one field's violation out of a conversion, to be prefixed with
 * the field's path; a value made of parts (a repeater's rows, a list of
 * tags) may carry one per part, the first as `$violation`.
 */
final class InputViolation extends Exception
{
    /** @var list<Violation> */
    public readonly array $others;

    /** @param list<Violation> $others */
    public function __construct(public readonly Violation $violation, array $others = [])
    {
        parent::__construct($violation->message);
        $this->others = $others;
    }

    /**
     * Several violations at once: the first, and the rest.
     *
     * @param non-empty-list<Violation> $violations
     */
    public static function of(array $violations): self
    {
        return new self($violations[0], array_slice($violations, 1));
    }

    /** @return non-empty-list<Violation> */
    public function all(): array
    {
        return [$this->violation, ...$this->others];
    }
}
