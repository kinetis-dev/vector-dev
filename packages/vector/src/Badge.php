<?php

declare(strict_types=1);

namespace Kinetis\Vector;

/**
 * A count shown beside a resource in the menu: the records matching a
 * filter in the list grammar — `['status' => ['eq' => 'open']]` — as the
 * user's own list would count them, scope and soft deletion included.
 */
final readonly class Badge
{
    /** @param array<string, mixed> $filter the list grammar's filter object, values as text */
    private function __construct(
        public array $filter,
        public string $color,
        public ?string $label,
    ) {}

    /**
     * @param array<string, mixed> $filter field => [operator => value], or field => value for `eq`; `or`/`and` hold filter objects
     * @param string $color a theme colour, such as `warning`
     * @param string|null $label what is counted, for the chip's accessible name: "open tickets"
     */
    public static function count(array $filter = [], string $color = 'primary', ?string $label = null): self
    {
        return new self(self::normalized($filter), $color, $label);
    }

    /**
     * @param array<array-key, mixed> $filter
     * @return array<string, mixed>
     */
    private static function normalized(array $filter): array
    {
        $normalized = [];

        foreach ($filter as $field => $condition) {
            $field = (string) $field;

            if (in_array($field, ['or', 'and'], true) && is_array($condition)) {
                $normalized[$field] = array_map(static fn (mixed $child): array => self::normalized(is_array($child) ? $child : []), array_values($condition));

                continue;
            }

            foreach (is_array($condition) ? $condition : ['eq' => $condition] as $operator => $value) {
                $normalized[$field][(string) $operator] = match (true) {
                    $value === true => 'true',
                    $value === false => 'false',
                    is_scalar($value) => (string) $value,
                    default => '',
                };
            }
        }

        return $normalized;
    }

    /** @return array{filter: array<string, mixed>, color: string, label: string|null} */
    public function toArray(): array
    {
        return ['filter' => $this->filter, 'color' => $this->color, 'label' => $this->label];
    }
}
