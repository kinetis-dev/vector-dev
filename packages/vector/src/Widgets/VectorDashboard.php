<?php

declare(strict_types=1);

namespace Kinetis\Vector\Widgets;

use Kinetis\Http\CurrentUserInterface;

/**
 * A named page of widgets. A concrete subclass carrying #[Dashboard]
 * is discovered at compile time; `widgets()` lists widget classes in
 * display order. The dashboard named `main` (or the first by name)
 * is the admin's home page.
 */
abstract class VectorDashboard
{
    public function name(): string
    {
        $short = (new \ReflectionClass($this))->getShortName();
        $short = preg_replace('/Dashboard$/', '', $short) ?? $short;

        return strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $short) ?? $short);
    }

    public function label(): string
    {
        return \Kinetis\Vector\Fields\Field::labelFrom(str_replace('-', '_', $this->name()));
    }

    public function icon(): ?string
    {
        return 'mdi-view-dashboard-outline';
    }

    /** @return list<class-string<VectorWidget>> */
    abstract public function widgets(): array;

    /** Request-time visibility of the whole page. */
    public function canView(CurrentUserInterface $user): bool
    {
        return true;
    }
}
