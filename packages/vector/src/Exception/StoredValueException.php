<?php

declare(strict_types=1);

namespace Kinetis\Vector\Exception;

use UnexpectedValueException;

/**
 * A stored value the field cannot show: a select's value that is not one
 * of its options, or a JSON column that holds no valid document. The
 * data is wrong, not the request, so it is never silently dropped; an
 * option removed from a select needs a data migration of the rows that
 * still hold it.
 */
final class StoredValueException extends UnexpectedValueException
{
    public static function unknownOption(string $field, string $value): self
    {
        return new self("Field \"{$field}\" holds \"{$value}\", which is not one of its options; migrate the stored data before removing an option.");
    }

    public static function invalidJson(string $field): self
    {
        return new self("Field \"{$field}\" holds a value that is not the JSON its field expects.");
    }
}
