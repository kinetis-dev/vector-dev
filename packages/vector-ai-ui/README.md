# @kinetis/vector-ai-ui

AI for the [Kinetis Vector](https://kinetis.dev/vector/docs/) admin: a
Nuxt layer over [`@kinetis/vector-ui`](https://www.npmjs.com/package/@kinetis/vector-ui),
the UI half of [`kinetis/vector-ai`](https://packagist.org/packages/kinetis/vector-ai).

**This release offers plain-language filters**, in every list's
toolbar; **the assistant**, a sparkle button in the app bar
opening a drawer beside the page (and *Ask the assistant…* in the ⌘K
palette), whose streamed answers link every `resource#id` they name to
that record, and whose proposed changes show as approval cards — the
change field by field, to approve or reject; **writing help**, a menu under the text inputs of a resource's
create and edit forms (not in bulk edit or action dialogs) and in the
rich-text and Markdown editors' toolbars that rewrites, shortens,
corrects or translates the text (or the selection) and shows the answer
beside it to replace or discard; and the provider-key panel of the `ai`
settings group.

Extend this layer instead of `@kinetis/vector-ui` in your Nuxt app
(standalone mode): `extends: ['@kinetis/vector-ai-ui']`. Hosted
(no-build) delivery comes in a later release.

Each release depends on exactly the `@kinetis/vector-ui` release that
`kinetis/vector` pins. Against a backend without
`kinetis/vector-ai` the layer renders nothing of its own.

Manual: <https://kinetis.dev/vector/docs/ai.html>.

## License

MIT
