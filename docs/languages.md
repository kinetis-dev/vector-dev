# Languages

Each user sees the admin in their own language. Vector's UI ships in
English and sixteen more: German, French, Dutch, Italian, Spanish,
Portuguese (Portugal and Brazil), Polish, Turkish, Russian, Ukrainian,
Hebrew, Arabic, Korean, Japanese and Simplified Chinese. Your
application's labels — resources, fields, enum options, actions,
widgets, dashboards, settings — are translated with it.

```{note}
English is the base language everywhere. Every other catalog, and the
demo's label translations, are **machine translations**. Review them
before relying on them — the Chinese, Japanese, Korean and Arabic ones
especially, with a native speaker — and override any message you want
worded differently (below).
```

## Switching language

The **language menu** in the app bar (the translate icon) lists the
languages on offer, each in its own name with its name in your current
language beneath (*Deutsch — German*), so a script you cannot read is
still recognisable. The list is in one order whatever language you are
in, so it never reshuffles on a switch: the languages' own names in
Unicode's language-neutral order — Latin-script names alphabetically,
then each other script as a group (Greek, Cyrillic, Hebrew, Arabic, the
Indic scripts, Thai, Korean, then Chinese and Japanese). The settings
page lists them the same way. Choosing one switches the whole
admin at once — menus, labels, dates and numbers — and is remembered as
your preference, on every device you sign in from. Hebrew and Arabic
turn the layout right to left.

Until you choose, the admin is in the default language the administrator
set; your browser's language plays no part.

## Which languages are offered

An administrator decides, under **Settings → Languages**: one switch per
language, all on at first, and the **Default language** — English unless
changed — for every user who has not chosen one. The default must be one
of the languages switched on, so at least one always is; the page
refuses to save otherwise. The language menu follows as soon as the settings are saved.

A language switched off cannot be chosen, not even through the API. A
user who had chosen it sees the admin in the default language from their
next page on — at once, for the administrator who saves the change — and
if it is switched on again, their choice returns.

## Translating your labels

Every label you declare is looked up as a translation key, in the
language being shown; a label without a translation is shown as you
wrote it. Put one PHP file per language in a directory and name it in
`VECTOR_TRANSLATIONS_DIR` (relative to the project root):

```php
<?php
// translations/de.php
return [
    'Products' => 'Produkte',
    'Price' => 'Preis',
    'Draft' => 'Entwurf',
    'Reprice the selected products?' => 'Die ausgewählten Produkte neu bepreisen?',
];
```

Keys may be the English text, as above, or keys of your own
(`'shop.products'`) that an `en.php` turns into English. Nested arrays
are flattened with dots. A regional tag falls back to its language
(`pt-BR` reads `pt.php`). The files are read once, when the worker
starts.

To use your framework's own translator instead, bind
`Kinetis\Vector\Translation\TranslatorInterface` in your application.

Translation happens when the admin asks for its descriptor, so the
compiled registry stays in your labels' own words. Record values are
data and are never translated.

## Changing the UI's messages

Add or override the UI's messages per locale and area:

```ts
export default defineAppConfig({
    vector: {
        messages: {
            de: {
                common: { save: 'Sichern' },
                list: { filter: { add: 'Filter hinzufügen' } },
            },
        },
    },
})
```

A runtime extension does the same without a build:

```js
window.Vector.defineMessages('de', { common: { save: 'Sichern' } })
```

The English catalog in the layer's `app/i18n/en/` names every key; every
shipped locale mirrors it, and a key a locale lacks falls back to
English. Messages use `{name}` placeholders and are never rendered as
HTML. A message that depends on a count lists its language's own forms,
separated by `|`, in the order zero, one, two, few, many, other — only
the ones that language has for whole numbers: two in English and German
(`1 change | {count} changes`), three in Hebrew and in Russian, one in
Japanese. A two-form override you add is read the English way in any
language.

## See also

- {doc}`configuration` — every environment key.
- {doc}`customizing-the-ui` — other ways to adapt the UI.
