<p align="center">
  <img src="https://kinetis.dev/vector/docs/_static/logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>@kinetis/vector-ai-ui</strong>
  <br>
  <strong>AI for the Kinetis Vector admin, as a Nuxt layer over @kinetis/vector-ui</strong>
</p>

<p align="center">
  <a href="https://www.npmjs.com/package/@kinetis/vector-ai-ui"><img src="https://img.shields.io/npm/v/@kinetis/vector-ai-ui?label=version" alt="npm Version"></a>
  <a href="https://www.npmjs.com/package/@kinetis/vector-ai-ui"><img src="https://img.shields.io/npm/dm/@kinetis/vector-ai-ui" alt="npm Downloads"></a>
  <a href="https://www.npmjs.com/package/@kinetis/vector-ai-ui"><img src="https://img.shields.io/npm/l/@kinetis/vector-ai-ui" alt="License"></a>
  <a href="https://github.com/kinetis-dev/vector-dev/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/vector-dev/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications. Vector AI is developed in the
[kinetis-dev/vector-dev](https://github.com/kinetis-dev/vector-dev)
monorepo, beside its PHP package, Vector's PHP package and admin UI, and
a demo application.

The UI half of [`kinetis/vector-ai`](https://packagist.org/packages/kinetis/vector-ai):
a Nuxt layer over
[`@kinetis/vector-ui`](https://www.npmjs.com/package/@kinetis/vector-ui)
that adds, through the admin's own extension points:

- **plain-language filters**, in every list's toolbar;
- **the assistant**: a sparkle button in the app bar opens a drawer
  beside the page (⌘J, or Ctrl+J on Windows and Linux, opens or closes it
  from anywhere). Its streamed answers link every `resource#id` they name
  to that record, and the changes it proposes show as approval cards: the
  change field by field, to approve or reject;
- **writing help**: a menu behind an icon inside the text inputs of a
  resource's create and edit forms (not in bulk edit or action dialogs),
  and in the rich-text and Markdown editors' toolbars. It rewrites,
  shortens, corrects or translates the text — the selection, or the whole
  text while nothing is selected — and shows the answer beside it to
  replace or discard. It is not offered on a field the server has writing
  help off for (`capabilities.ai.noWriting`: a unique string such as a
  SKU, or a field with the `ai.writing` meta false);
- **the provider-key panel** of the `ai` settings group.

Each part renders only while the descriptor's `capabilities.ai` offers
its feature, so against a backend without `kinetis/vector-ai` the layer
renders nothing of its own.

## Installation

```sh
pnpm add @kinetis/vector-ai-ui nuxt vuetify vuetify-nuxt-module
```

```ts
// nuxt.config.ts
export default defineNuxtConfig({
    extends: ['@kinetis/vector-ai-ui'],
    runtimeConfig: { public: { vector: { apiBase: 'https://api.example.com' } } },
})
```

Extend this layer instead of `@kinetis/vector-ui`: it extends
`@kinetis/vector-ui` itself, and depends on exactly the release your
`kinetis/vector` pins. The AI layer reaches a standalone admin only; the
hosted admin that `kinetis vector:install-ui` installs does not include
it. Add `Retry-After` to the API's `CorsMiddleware` `exposedHeaders`, so
the admin can say when a spent daily budget renews. See
[AI](https://kinetis.dev/vector/docs/ai.html).

## Configuration

The layer has no settings of its own: the admin is configured as
[`@kinetis/vector-ui`](https://www.npmjs.com/package/@kinetis/vector-ui)
is. What AI is offered, through which providers and to whom, is the
server's: the `VECTOR_AI_*` keys, the `ai` settings group and the access
policy, in
[kinetis.dev/vector/docs/ai.html](https://kinetis.dev/vector/docs/ai.html).

## License

MIT — see [LICENSE](LICENSE).
