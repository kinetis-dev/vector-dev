# kinetis/vector-ai

AI for [Kinetis Vector](https://kinetis.dev/vector/docs/): plain-language
filters, an in-admin assistant and writing help, over the model providers
an operator configures. Built on
[kinetis/neuron](https://kinetis.dev/docs/neuron.html), so every provider
call suspends the calling Fiber instead of blocking the worker.

**This release offers plain-language filters**: an admin describes the
records they want on a list, and the model's answer is validated by
the list's own validator before it is previewed. It also lays the
foundations the other features build on — the operator configuration,
the sealed provider keys, the `ai` settings group, the access policy,
the model boundary and the usage ledger. The assistant and writing help
arrive in later releases.

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
