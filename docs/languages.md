# Languages

The admin's own strings — buttons, menus, dialogs, validation and
notifications — come from a message catalog. Labels in the descriptor —
resources, fields, actions, enum options — are your application's, and
arrive in whatever language you wrote them.

## Choosing the language

The locale is, in order:

1. `VECTOR_UI_LOCALE` (`de`, `pt-BR`, …), in hosted mode;
2. `app.config` `vector.locale`;
3. English.

Vuetify's own strings, such as pagination, follow the same locale.

## Adding or changing messages

English ships with the layer and is the fallback, string by string, for
every other locale. Add or override messages per locale and area:

```ts
export default defineAppConfig({
    vector: {
        locale: 'de',
        messages: {
            de: {
                common: { save: 'Speichern', cancel: 'Abbrechen' },
                list: { exportCsv: 'CSV exportieren', filter: { add: 'Filter hinzufügen' } },
            },
        },
    },
})
```

A runtime extension does the same without a build:

```js
window.Vector.defineMessages('de', { common: { save: 'Speichern' } })
```

The English catalog in the layer's `app/i18n/en/` names every key.
Messages use `{name}` placeholders and `a | b | c` plural forms, and are
never rendered as HTML.

## See also

- {doc}`customizing-the-ui` — other ways to adapt the UI.
