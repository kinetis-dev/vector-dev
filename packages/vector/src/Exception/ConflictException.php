<?php

declare(strict_types=1);

namespace Kinetis\Vector\Exception;

/** The record changed since the client read it: the client decides again on the current row. */
final class ConflictException extends VectorException
{
    public static function stale(string $resource): self
    {
        return new self("This {$resource} record was changed by someone else. Reload it and apply your change again.");
    }

    /** @param list<string> $fields the labels of the fields changed since */
    public static function changedSince(string $resource, array $fields): self
    {
        return new self('This ' . $resource . ' record changed since that entry (' . implode(', ', $fields) . '). Revert those changes first, or edit it by hand.');
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
