<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Filters;

/**
 * A sentence as a list filter, in the list's own grammar: `filter`, the
 * conditions that must all hold (`{field: {op: value}}`); `groups`, each a
 * list of alternatives one of which must hold, as the list's FilterBar
 * keeps them; `q`, the free-text search. Validated by the list's own
 * validator before it is built. `unresolved` names each relation the
 * sentence named in words that more than one record matches, with those
 * records; its condition is left out until someone picks one.
 */
final readonly class FilterTranslation
{
    /**
     * @param array<string, array<string, string>> $filter
     * @param list<list<array<string, array<string, string>>>> $groups
     * @param list<array{field: string, op: string, text: string, candidates: list<array{id: int|string, label: string}>}> $unresolved
     */
    public function __construct(
        public array $filter,
        public array $groups,
        public ?string $q,
        public string $explanation,
        public array $unresolved,
    ) {}

    /** @return array<string, mixed> the response body; `filter` an object even when empty (an alternative never is: it holds at least one condition) */
    public function toArray(): array
    {
        return [
            'filter' => $this->filter === [] ? new \stdClass() : $this->filter,
            'groups' => $this->groups,
            'q' => $this->q,
            'explanation' => $this->explanation,
            'unresolved' => $this->unresolved,
        ];
    }
}
