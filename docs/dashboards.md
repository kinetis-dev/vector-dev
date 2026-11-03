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
| `queues` | The queue dashboard's card: jobs waiting and failed, linking to the Queues page. Only `QueuesWidget` uses it ({doc}`queues`). |

A value's format is a number, `money:EUR`, `percent` or `duration`.

An application can replace a built-in type's card in the UI, through
`vector.widgets` in its `app.config` or `window.Vector.defineWidget()`
in a runtime extension ({doc}`customizing-the-ui`); the data keeps that
type's shape.

### Extension widget types

`type()` may also return an extension type: a lower-case kebab-case name
that is none of the six above, such as `sparkline`. Any other type is
refused when the registry compiles. Vector ships no card for an
extension type. The application registers the card for it in its UI,
either in its runtime extension with
`window.Vector.defineWidget(type, component)` or in its `app.config`
under `vector.widgets` ({doc}`customizing-the-ui`). A card whose type
has no renderer says that none is registered for the type, instead of
guessing at a shape.

`calculate()` answers such a widget with `WidgetResult::extension()`:

```php
public function type(): string { return 'sparkline'; }

public function calculate(WidgetRequest $request): WidgetResult
{
    return WidgetResult::extension('sparkline', ['samples' => [3, 1, 4]]);
}
```

The data reaches the card as the widget wrote it, beside the `type`,
`range` and `cached` that Vector sets on every answer, so it cannot use
those three keys; a built-in type is refused too, since its card reads
the shape its own factory builds. None of the built-in shapes applies.
Ranges, caching, `canView()` and translated labels work as for any
widget.

## Shaping a widget

| Method | Effect |
|---|---|
| `ranges()` | The ranges offered, as key => label, the first the default; `7d`, `30d`, `90d` and `1y` by default, and an empty array for none. |
| `width()` | 3, 4, 6 or 12 columns of the grid. |
| `name()`, `label()`, `icon()` | The URL segment (by default the class name without `Widget`, in kebab-case: `RevenueWidget` is `revenue`) and how it is shown. |
| `canView()` | Who sees it, on top of the dashboard's or resource's own visibility. |
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

A dashboard's name is its class name without `Dashboard`, in kebab-case
(`MainDashboard` is `main`), unless `name()` says otherwise. The
dashboard named `main` — or, without one, the first — is the admin's
home page; the others appear in the navigation drawer under their own
labels. `canView()` hides a whole dashboard from a user.

## Resource widgets

A resource's `widgets()` appear above its list. A widget runs its own
query: when the resource's `scope()` narrows what a user may see, narrow
the widget's query the same way, and leave `cacheShared()` false so one
admin is never shown another's figures.

## See also

- {doc}`resources` — badges, the other at-a-glance count.
- {doc}`customizing-the-ui` — a renderer of your own for a widget type.
