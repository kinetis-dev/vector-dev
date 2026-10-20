<?php

declare(strict_types=1);

namespace Kinetis\Vector;

/**
 * The searchable text fields a resource's full-text index covers: `q`
 * matches them by whole words with the engine's full-text predicate
 * instead of LIKE. The application creates the index in its own
 * migration — on MySQL a FULLTEXT index over exactly these columns, in
 * this order; on PostgreSQL a GIN index over the same `to_tsvector()`
 * expression the search builds.
 */
final readonly class FullText
{
    /** @param list<string> $fields */
    private function __construct(
        public array $fields,
        public string $config,
    ) {}

    /** @param string ...$fields text fields of the resource, in the order the index lists their columns */
    public static function on(string ...$fields): self
    {
        return new self(array_values($fields), 'simple');
    }

    /** The PostgreSQL text search configuration, such as `english`; `simple` by default. MySQL ignores it. */
    public function config(string $config): self
    {
        return new self($this->fields, $config);
    }
}
