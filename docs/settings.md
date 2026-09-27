# Settings

A settings group is a set of values an admin edits on the **Settings**
page, stored by Vector in its own table. It is a class extending
`SettingsGroup` with a `#[Settings]` attribute:

```php
#[Settings]
final class StoreSettings extends SettingsGroup
{
    public function fields(): array
    {
        return [
            Text::make('storeName')->required(),
            Currency::make('defaultCurrency'),
            Boolean::make('maintenanceMode'),
        ];
    }

    public function canManage(CurrentUserInterface $user): bool
    {
        return $user instanceof Staff && $user->isOwner;
    }
}
```

The fields are the same as a resource's, with validation, defaults and
conditions. `name()`, `label()` and `icon()` override how the group is
named and shown. Saving validates the group like a form and replaces its
values, in Vector's `vector_settings` table.

## Branding

Vector ships one group, **Branding**: the admin's title, logo URL,
primary colours for the light and dark themes, default theme and footer
line. It applies at runtime, over the UI's built-in theme, without a
rebuild.

A setting is always a value, rendered escaped; it is never markup or
script. Adding script to the admin is a deployment decision, made with
`VECTOR_SHELL_HEAD` or runtime extensions ({doc}`customizing-the-ui`).

## See also

- {doc}`customizing-the-ui` — theming beyond branding.
- {doc}`authorization` — who may manage what.
