# @kinetis/vector-ai-ui

AI for the [Kinetis Vector](https://kinetis.dev/vector/docs/) admin: a
Nuxt layer over [`@kinetis/vector-ui`](https://www.npmjs.com/package/@kinetis/vector-ui),
the UI half of [`kinetis/vector-ai`](https://packagist.org/packages/kinetis/vector-ai).

**This release offers plain-language filters**, in every list's
toolbar, and the provider-key panel of the `ai` settings group. The
assistant and writing help arrive in later releases.

Extend this layer instead of `@kinetis/vector-ui` in your Nuxt app
(standalone mode): `extends: ['@kinetis/vector-ai-ui']`. Hosted
(no-build) delivery comes in a later release.

Each release depends on exactly the `@kinetis/vector-ui` release that
`kinetis/vector` pins. Against a backend without
`kinetis/vector-ai` the layer renders nothing of its own.

Manual: <https://kinetis.dev/vector/docs/ai.html>.

## License

MIT
