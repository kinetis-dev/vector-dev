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
still recognizable. The list keeps one order whatever language you are
in, so it never reshuffles on a switch: Latin-script names
alphabetically, then Cyrillic, Hebrew, Arabic, Korean, Japanese and
Chinese. The settings page lists them the same way. Choosing one
switches the whole admin at once — menus, labels, dates and numbers —
and is remembered as your preference, on every device you sign in from.

Until you choose, the admin is in the default language the administrator
set; your browser's language plays no part.

Every list of languages — the menu, the switches, the default's options
— follows `Kinetis\Vector\Translation\Locales::SHIPPED`.

## Which languages are offered

An administrator decides, under **Settings → Languages**: one switch per
language, all on at first, and the **Default language** — English unless
changed — for every user who has not chosen one. The default must be one
of the languages switched on, so at least one always is; the page
refuses to save otherwise. The language menu follows as soon as the
settings are saved.

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

Vector binds a `MessagesTranslator` over three layers, each overriding
the one before it, key by key:

1. Vector's own `translations/`: the labels of its built-in settings
   groups, machine-translated like the UI's catalogs.
2. The directory each installed package declares for the labels it
   emits on the server, in package-name order (see below).
3. Your `VECTOR_TRANSLATIONS_DIR`.

So your wording always wins, over a package's and over Vector's. To use
your framework's own translator instead, bind
`Kinetis\Vector\Translation\TranslatorInterface` in your application.

### Translations in a Composer package

A package that declares labels on the server, such as a dashboard
card's, ships their translations in its own directory of
`{locale}.php` files, in the format above, and names it, relative to the
package's root, in its `composer.json`:

```json
{
    "extra": {
        "kinetis": {
            "vector": { "translations": "resources/translations" }
        }
    }
}
```

One directory per package, as a string. Vector reads the declaration
from Composer's `installed.json` when it compiles its registry
(`kinetis build`, or every boot in development), beside the package's
UI extensions ({doc}`customizing-the-ui`), so it does not matter whether
the package boots before or after Vector. Run `composer install` after a
change to the package's `composer.json`. The directory must be inside
the package: a relative path with no `.`, `..` or hidden segment and no
symlink out of it. Vector skips any other declaration, and a directory
gone by the time a worker boots, and writes the reason to the PHP error
log; neither stops the application. A missing `VECTOR_TRANSLATIONS_DIR`
still does. A package that ships widgets, actions or settings of its
own translates their labels this way.

Translation happens at response time — the descriptor, settings groups,
dashboards and widget results — so the compiled registry stays in your
labels' own words. The MCP schema resource is translated for the
user's preference too. Record values are data and are never translated.

Validation messages, the framework's and Vector's own, are in English.

## In the UI

The layer ships English (the base and the fallback, key by key) and the
catalogs above, with the codes `pt` (European) and `pt-BR`, and
`zh-Hans` for Simplified Chinese. The descriptor's `i18n` names the
user's language and the languages on offer. The language menu lists
them in the server's order, naming each in itself with its name in the
current language beneath (`languageName()`, from `Intl.DisplayNames`);
switching calls
`useVectorLocale().choose(code)`, which stores the user's `locale`
preference and reloads the descriptor.

Before the descriptor loads, the UI starts in the hosted shell's
language, else the one this browser last used, else `app.config`
`vector.locale`. Applying a language switches the catalog, Vuetify's
component strings, the `Intl` formatting of dates, numbers and money,
and `<html lang dir>`. Hebrew and Arabic render right to left, and the
layer's own components use logical spacing (`ms-`/`me-`) so they
mirror.

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
separated by `|`, in CLDR order — zero, one, two, few, many, other —
only the ones that language has for whole numbers
(`pluralCategories()`): two in English and German
(`1 change | {count} changes`), three in Hebrew (one, two, other) and in
Russian (one, few, many), one in Japanese, six in Arabic. `pluralRule()`
picks the form by the browser's `Intl.PluralRules`. A two-form override
you add is read the English way in any language.

## See also

- {doc}`configuration` — every environment key.
- {doc}`customizing-the-ui` — other ways to adapt the UI.
