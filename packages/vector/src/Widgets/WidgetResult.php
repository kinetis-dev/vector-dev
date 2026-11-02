<?php

declare(strict_types=1);

namespace Kinetis\Vector\Widgets;

/** What a widget answers, shaped by its type; JSON as the UI reads it. */
final readonly class WidgetResult
{
    /** @param array<string, mixed> $data */
    private function __construct(public string $type, public array $data) {}

    /**
     * A number, with the previous range's number for a delta. `format`
     * is `number`, `money:EUR`, `percent` or `duration`.
     */
    public static function stat(int|float|null $value, int|float|null $previous = null, string $format = 'number', ?string $caption = null): self
    {
        return new self(VectorWidget::STAT, ['value' => $value, 'previous' => $previous, 'format' => $format, 'caption' => $caption]);
    }

    /**
     * Points in order; `x` a label (a day), `y` a number.
     *
     * @param list<array{x: string, y: int|float}> $points
     */
    public static function trend(array $points, string $format = 'number', int|float|null $total = null): self
    {
        return new self(VectorWidget::TREND, ['points' => $points, 'format' => $format, 'total' => $total]);
    }

    /**
     * Slices with labels; the UI sizes them by value.
     *
     * @param list<array{label: string, value: int|float, color?: string|null}> $slices
     */
    public static function partition(array $slices, string $format = 'number'): self
    {
        return new self(VectorWidget::PARTITION, ['slices' => $slices, 'format' => $format]);
    }

    public static function progress(int|float $value, int|float $target, string $format = 'number', ?string $caption = null): self
    {
        return new self(VectorWidget::PROGRESS, ['value' => $value, 'target' => $target, 'format' => $format, 'caption' => $caption]);
    }

    /**
     * @param list<array{key: string, label: string, align?: 'start'|'end'}> $columns
     * @param list<array<string, mixed>> $rows
     */
    public static function table(array $columns, array $rows, ?string $link = null): self
    {
        return new self(VectorWidget::TABLE, ['columns' => $columns, 'rows' => $rows, 'link' => $link]);
    }

    /**
     * The answer of a widget whose type() is an extension type: `$data`
     * reaches the renderer the UI registered for `$type` as it is, with
     * none of a built-in card's shape. A built-in type is refused, since
     * its card reads the shape its own factory builds; so are the keys
     * Vector writes into every answer, `type`, `range` and `cached`.
     *
     * @param array<string, mixed> $data
     */
    public static function extension(string $type, array $data = []): self
    {
        if (!VectorWidget::isExtensionType($type)) {
            throw new \InvalidArgumentException("\"{$type}\" is no extension widget type: one is lower-case kebab-case and none of " . implode(', ', VectorWidget::BUILT_IN_TYPES) . ', which have factories of their own.');
        }

        foreach (['type', 'range', 'cached'] as $reserved) {
            if (array_key_exists($reserved, $data)) {
                throw new \InvalidArgumentException("An extension widget's data cannot carry \"{$reserved}\", which Vector sets on every answer.");
            }
        }

        return new self($type, $data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['type' => $this->type, ...$this->data];
    }
}
