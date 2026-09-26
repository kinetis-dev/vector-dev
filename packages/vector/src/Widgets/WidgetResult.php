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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['type' => $this->type, ...$this->data];
    }
}
