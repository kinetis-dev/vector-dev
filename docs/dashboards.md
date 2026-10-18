# Dashboards and widgets

A widget is a card computed on the server for a date range. Widgets sit
on dashboards, or above a resource's list.

```php
final class RevenueStat extends VectorWidget
{
    public function __construct(private readonly SqlLink $db) {}

    public function type(): string { return self::STAT; }
    public function cacheSeconds(): int { return 60; }

    public function calculate(WidgetRequest $request): WidgetResult
    {
        // one query for $request->from .. $request->to
        return WidgetResult::stat($current, $previous, 'money:EUR');
    }
}
```

## Widget types

| Type | Shows |
|---|---|
| `stat` | One figure, compared with the previous period. |
| `trend` | A series over time. |
| `partition` | A breakdown into slices, such as orders by status. |
| `progress` | A figure against a target. |
| `table` | A few rows, optionally linking to the full list. |

A value's format is a number, `money:EUR`, `percent` or `duration`.

## Shaping a widget

| Method | Effect |
|---|---|
| `ranges()` | The ranges offered; `7d`, `30d`, `90d` and `1y` by default. |
| `width()` | 3, 4, 6 or 12 columns of the grid. |
| `canView()` | Who sees it. |
| `cacheSeconds()` | Above zero, memoizes the result in a bounded per-worker cache, keyed by widget, range and the account it was computed for. |
| `cacheShared()` | Shares one cached result between all admins, for figures that are the same for everyone. |

## Dashboards

```php
#[Dashboard]
final class MainDashboard extends VectorDashboard
{
    public function widgets(): array { return [RevenueStat::class, OrdersTrend::class]; }
}
```

The dashboard named `main` is the admin's home page. Others appear in
the navigation drawer under their own names.

## Resource widgets

A resource's `widgets()` appear above its list. A widget runs its own
query: when the resource's `scope()` narrows what a user may see, narrow
the widget's query the same way, and leave `cacheShared()` false so one
admin is never shown another's figures.

## See also

- {doc}`resources` — badges, the other at-a-glance count.
- {doc}`customizing-the-ui` — a renderer of your own for a widget type.
