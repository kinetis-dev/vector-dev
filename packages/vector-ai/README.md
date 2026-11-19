# kinetis/vector-ai

AI for [Kinetis Vector](https://kinetis.dev/vector/docs/): plain-language
filters, an in-admin assistant and writing help, over the model providers
an operator configures. Built on
[kinetis/neuron](https://kinetis.dev/docs/neuron.html), so every provider
call suspends the calling Fiber instead of blocking the worker.

**This release lays the foundations only.** It adds the operator
configuration, the sealed provider keys, the `ai` settings group, the
access policy and the usage ledger; the
features themselves arrive in later releases, and until then the admin
offers none of them.

```console
composer require kinetis/vector-ai
vendor/bin/kinetis vector-ai:install      # the credentials and usage tables
vendor/bin/kinetis migrate
```

The UI half is `@kinetis/vector-ai-ui`, a Nuxt layer: add it to your
Nuxt app's `extends` (standalone mode). Hosted (no-build) delivery of it
comes in a later release.

Configuration, the settings, key sealing and data governance are in the
manual: <https://kinetis.dev/vector/docs/ai.html>.

## License

MIT
