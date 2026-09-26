<?php

declare(strict_types=1);

namespace Kinetis\Vector\Write;

use Exception;
use Kinetis\Validation\Violation;

/** Carries one field's violation out of a conversion, to be prefixed with the field's path. */
final class InputViolation extends Exception
{
    public function __construct(public readonly Violation $violation)
    {
        parent::__construct($violation->message);
    }
}
