<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields\Layout;

use Kinetis\Vector\Fields\Field;

/**
 * A group of tabs, each holding fields, field names or sections:
 * `Tabs::make()->tab('General', [...])->tab('SEO', [...])`. Tabs sit at
 * the top level only.
 */
final class Tabs extends Layout
{
    /** @var list<array{label: string, children: list<Field|string|Section>}> */
    private array $tabs = [];

    private function __construct(private readonly ?string $label) {}

    public static function make(?string $label = null): self
    {
        return new self($label);
    }

    /** @param list<Field|string|Section> $children */
    public function tab(string $label, array $children): self
    {
        if (trim($label) === '') {
            throw new \InvalidArgumentException('A tab needs a label.');
        }

        $this->tabs[] = ['label' => $label, 'children' => self::children($children, true, "Tab \"{$label}\"")];

        return $this;
    }

    public function label(): ?string
    {
        return $this->label;
    }

    /** @return list<array{label: string, children: list<Field|string|Section>}> */
    public function tabs(): array
    {
        return $this->tabs;
    }
}
