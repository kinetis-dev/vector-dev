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
    /** The queue dashboard's card ({@see \Kinetis\Vector\Queues\Widgets\QueuesWidget}), which loads its own figures. */
    public const string QUEUES = 'queues';

    /** The types the UI ships a card for, each with the data shape its WidgetResult factory builds. */
    public const array BUILT_IN_TYPES = [self::STAT, self::TREND, self::PARTITION, self::PROGRESS, self::TABLE, self::QUEUES];

    /** A widget name's grammar, and an extension type's: lower-case kebab-case. */
    public const string KEBAB_CASE = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * Whether `$type` names an extension type: lower-case kebab-case and
     * none of BUILT_IN_TYPES.
     */
    public static function isExtensionType(string $type): bool
    {
        return preg_match(self::KEBAB_CASE, $type) === 1 && !in_array($type, self::BUILT_IN_TYPES, true);
    }

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

    /**
     * Decides the card the UI renders: one of the type constants, or an
     * extension type.
     *
     * An extension type is a lower-case kebab-case name that is none of
     * the built-in ones (`isExtensionType()`), such as `sparkline`.
     * Vector ships no card for it: the application registers the card
     * for the type in its UI, through `window.Vector.defineWidget(type,
     * component)` in its runtime extension or `vector.widgets` in its
     * `app.config`. Without one the card says that no renderer is
     * registered for the type. `calculate()` answers it with
     * `WidgetResult::extension()`, whose data Vector passes to that card
     * as it is.
     */
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
