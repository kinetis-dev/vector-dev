# kinetis/vector-ai

AI for [Kinetis Vector](https://kinetis.dev/vector/docs/): plain-language
filters, an in-admin assistant and writing help, over the model providers
an operator configures. Built on
[kinetis/neuron](https://kinetis.dev/docs/neuron.html), so every provider
call suspends the calling Fiber instead of blocking the worker.

**This release offers plain-language filters, the read-only
assistant and writing help.** On a list, an admin describes the records they want, and
the model's answer is validated by the list's own validator before it
is previewed. In a drawer beside any page, the assistant answers
questions about the admin's data, reading it with Vector's own MCP
tools as the signed-in person — governed, budgeted, and never able to
change anything — and streams its answer (`POST /vector/ai/assistant`,
the Vercel AI SDK data-stream protocol, or JSON). Under a text input,
and in the toolbar of a Markdown or rich-text editor, writing help
rewrites, shortens, corrects or translates the text
(`POST /vector/ai/writing/{resource}/{field}`); the answer is put in the
input for the person to review and save as their own edit — the server
never writes it. All three rest on the foundations every feature
shares — the operator configuration, the sealed provider keys, the `ai`
settings group, the access policy, the model boundary and the usage
ledger. The assistant proposing changes arrives in a later release.

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
