<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields\Layout;

use Kinetis\Vector\Fields\Field;

/**
 * A titled group of fields on a grid of its own:
 * `Section::make('Pricing')->description('…')->collapsible()->columns(2)->fields([...])`.
 * A section holds fields (or field names); it may sit at the top level
 * or inside a tab, never inside another section.
 */
final class Section extends Layout
{
    private ?string $description = null;

    private bool $collapsible = false;

    private bool $collapsed = false;

    private int $columns = 2;

    /** @var list<Field|string> */
    private array $children = [];

    private function __construct(private readonly string $label)
    {
        if (trim($label) === '') {
            throw new \InvalidArgumentException('A section needs a label.');
        }
    }

    public static function make(string $label): self
    {
        return new self($label);
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** The section folds open and closed; it starts open. */
    public function collapsible(bool $collapsible = true): self
    {
        $this->collapsible = $collapsible;
        $this->collapsed = $collapsible && $this->collapsed;

        return $this;
    }

    /** The section folds, and starts closed. */
    public function collapsed(bool $collapsed = true): self
    {
        $this->collapsed = $collapsed;
        $this->collapsible = $this->collapsible || $collapsed;

        return $this;
    }

    /** The grid's columns, 1 to 4; a field spans one unless it says otherwise (columnSpan()). */
    public function columns(int $columns): self
    {
        if ($columns < 1 || $columns > 4) {
            throw new \InvalidArgumentException("Section \"{$this->label}\": columns is 1 to 4, not {$columns}.");
        }

        $this->columns = $columns;

        return $this;
    }

    /** @param list<Field|string> $fields */
    public function fields(array $fields): self
    {
        /** @var list<Field|string> $children */
        $children = self::children($fields, false, "Section \"{$this->label}\"");
        $this->children = $children;

        return $this;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return list<Field|string> */
    public function items(): array
    {
        return $this->children;
    }

    /** @return array{description: string|null, collapsible: bool, collapsed: bool, columns: int} */
    public function options(): array
    {
        return ['description' => $this->description, 'collapsible' => $this->collapsible, 'collapsed' => $this->collapsed, 'columns' => $this->columns];
    }
}
