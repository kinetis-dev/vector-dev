<?php

declare(strict_types=1);

namespace Kinetis\Vector\Widgets;

use Kinetis\Http\CurrentUserInterface;

/**
 * One card of a dashboard or of a resource's index: a number, a series,
 * a breakdown, a progress bar or a small table, computed for a range.
 * Definition methods run at compile time on an instance built without
 * its constructor; `calculate()` runs at request time on an instance
 * the request container builds, so it may inject the link, the entity
 * manager or a settings store.
 */
abstract class VectorWidget
{
    public const string STAT = 'stat';
    public const string TREND = 'trend';
    public const string PARTITION = 'partition';
    public const string PROGRESS = 'progress';
    public const string TABLE = 'table';

    /** The URL segment; kebab-case of the class name by default. */
    public function name(): string
    {
        $short = (new \ReflectionClass($this))->getShortName();
        $short = preg_replace('/Widget$/', '', $short) ?? $short;

        return strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $short) ?? $short);
    }

    public function label(): string
    {
        return \Kinetis\Vector\Fields\Field::labelFrom(str_replace('-', '_', $this->name()));
    }

    /** One of the type constants; decides the card the UI renders. */
    abstract public function type(): string;

    public function icon(): ?string
    {
        return null;
    }

    /**
     * The ranges the card offers, as `key => label`; the first is the
     * default. Empty means the widget has no range selector.
     *
     * @return array<string, string>
     */
    public function ranges(): array
    {
        return ['7d' => '7 days', '30d' => '30 days', '90d' => '90 days', '1y' => '1 year'];
    }

    /** Card width in twelfths of the row: 3, 4, 6 or 12. */
    public function width(): int
    {
        return 4;
    }

    /** Seconds a computed result is reused for by this worker; 0 computes on every request. */
    public function cacheSeconds(): int
    {
        return 0;
    }

    /**
     * Whether one cached result serves every admitted account. False, the
     * default, memoizes per account: a widget that reads `$request->user`,
     * or a query the resource's `scope()` narrows, then never shows one
     * admin another's numbers. Declare true only for a result that is the
     * same whoever asks, so the memo is shared.
     */
    public function cacheShared(): bool
    {
        return false;
    }

    /** Request-time visibility, on top of the dashboard's or resource's own. */
    public function canView(CurrentUserInterface $user): bool
    {
        return true;
    }

    abstract public function calculate(WidgetRequest $request): WidgetResult;
}
