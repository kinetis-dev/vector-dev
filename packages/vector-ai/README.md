<p align="center">
  <img src="https://kinetis.dev/vector/docs/_static/logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/vector-ai</strong>
  <br>
  <strong>AI for the Kinetis Vector admin, on kinetis/neuron</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/vector-ai"><img src="https://img.shields.io/packagist/v/kinetis/vector-ai?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/vector-ai"><img src="https://img.shields.io/packagist/dt/kinetis/vector-ai" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/vector-ai"><img src="https://img.shields.io/packagist/php-v/kinetis/vector-ai" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/vector-ai"><img src="https://img.shields.io/packagist/l/kinetis/vector-ai" alt="License"></a>
  <a href="https://github.com/kinetis-dev/vector-dev/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/vector-dev/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications. Vector AI is developed in the
[kinetis-dev/vector-dev](https://github.com/kinetis-dev/vector-dev)
monorepo, beside its UI layer, Vector's PHP package and admin UI, and a
demo application.

AI for the [Kinetis Vector](https://kinetis.dev/vector/docs/) admin,
over the model providers an operator configures. Built on
[`kinetis/neuron`](https://kinetis.dev/docs/neuron.html), so every
provider call suspends the calling Fiber instead of blocking the worker.
Its UI is part of
[`@kinetis/vector-ui`](https://www.npmjs.com/package/@kinetis/vector-ui),
which shows it when this package publishes `capabilities.ai`.

- **Plain-language filters.** On a list, an admin describes the records
  they want; the model's answer is validated by the list's own validator,
  then filters the list at once, with Undo.
- **The assistant.** In a drawer beside any page, it answers questions
  about the admin's data, reading it with Vector's own MCP tools as the
  signed-in person, and streams its answer. It cannot change anything
  itself: where an administrator allows it, it proposes a change, which
  runs only once the person approves it.
- **Writing help.** Behind an icon inside a text input, at the field's
  end, and in the toolbar of a Markdown or rich-text editor, it rewrites,
  shortens, corrects or translates the text. The answer is put in the input for the person to review and save
  as their own edit; the server never writes it.
- **Bulk rewrite proposals.** The queued `ProposeRewrites` action leaves
  a CSV of proposed texts on its job, which the admin reviews and imports
  in update mode. It writes no record either.

Every feature rests on the same foundations: the operator configuration,
the sealed provider keys, the `ai` settings group, the access policy, the
model boundary that decides what record data is sent to a model, and the
usage ledger that holds each user to a daily token budget. Resources and
fields steer the model boundary and writing help through Vector's
metadata bag:

```php
#[Resource(entity: Customer::class, meta: ['ai.send' => false])]   // nothing of this resource
Email::make('email')->meta('ai.send', false),                     // nor this field
Text::make('sku')->unique(),                                      // no writing help: an identifier
Text::make('code')->unique()->meta('ai.writing', true),           // writing help after all
```

Writing help is offered on `string`, `text`, `markdown` and `richtext`
fields, but not on a unique string; `ai.writing` turns it off for a
field, or on for a unique one, independently of `ai.send`.

## Provides

Installing this package auto-registers, via `extra.kinetis`:

- **Routes** under `/vector/ai`, in Vector's `vector` middleware group:
  `POST /vector/ai/filters/{resource}`, `POST /vector/ai/assistant`
  (the Vercel AI SDK data-stream protocol, or JSON),
  `POST /vector/ai/approvals/{id}/approve` and `/reject`,
  `POST /vector/ai/writing/{resource}/{field}`, and
  `GET /vector/ai/credentials` with `PUT` and
  `DELETE /vector/ai/credentials/{provider}`.
- **Commands** on `vendor/bin/kinetis`: `vector-ai:install` and
  `vector-ai:approvals:prune`.
- **The `ai` settings group**, *AI assistant*: each feature's switch,
  provider and model, the daily token budget, the assistant's limits and
  what record data is sent to a model.
- **`capabilities.ai`** in `GET /vector/schema`, from a shell
  contributor: per user, which AI features the admin offers and which
  resources and fields are kept from the model.
- **Bindings**, once per worker, from `PackageBootstrap`: the parsed
  configuration, one HTTP client per provider origin and one signing
  client per Bedrock region, the box that seals provider keys and,
  unless the application binds its own, the default
  `AiAccessInterface`, which admits everyone Vector's access policy
  admits.

`ProposeRewrites` is not registered: a resource opts in by listing a
subclass of it in its `actions()`. Authentication stays the
application's, as Vector has it. To decide who may use and configure
AI, bind your own `Kinetis\VectorAi\Auth\AiAccessInterface` on AppScope.

## Configuration

Read from the environment (or `.env`) via `Kinetis\Config`, once, at
boot; a value that cannot work stops the boot, naming its key. `<NAME>`
is a provider's name in upper case, a dash written as an underscore.

| Key | Default | Purpose |
|---|---|---|
| `VECTOR_AI_PROVIDERS` | *(unset)* | The providers the admin may call, by name; unset, no AI feature is offered. |
| `VECTOR_AI_<NAME>_TYPE` | *(none)* | `openai`, `anthropic`, `openai-like`, `ollama`, `bedrock` or `fake`. |
| `VECTOR_AI_<NAME>_BASE_URL` | *(the type's)* | The provider's API; required for `openai-like` and `ollama`. |
| `VECTOR_AI_<NAME>_KEY` | *(unset)* | The provider's API key; unset, an administrator stores one on the Settings page. |
| `VECTOR_AI_<NAME>_MODELS` | *(unset)* | The models the settings may choose; unset admits any. |
| `VECTOR_AI_<NAME>_REGION` | *(none)* | The AWS region of a `bedrock` provider. |
| `VECTOR_AI_KEY` | *(unset)* | `base64:` and 32 random bytes, which seal administrators' provider keys; unset, none can be stored. |
| `VECTOR_AI_TIMEOUT` | `90` | Seconds per provider call. |
| `VECTOR_AI_MAX_RESPONSE_BYTES` | `4194304` | The largest provider response read. |
| `VECTOR_AI_MAX_OUTPUT_TOKENS` | `2048` | The most tokens a model answers with. |

Every key, the settings, key sealing and data governance:
[kinetis.dev/vector/docs/ai.html](https://kinetis.dev/vector/docs/ai.html).

## Installation

```sh
composer require kinetis/vector-ai
vendor/bin/kinetis vector-ai:install    # the credentials, usage, approvals and rewrites tables
vendor/bin/kinetis migrate
```

Requires PHP 8.4 or 8.5 with `ext-sodium`,
[`kinetis/framework`](https://github.com/kinetis-dev/framework) 1.30.0
or later and [`kinetis/vector`](https://packagist.org/packages/kinetis/vector)
0.16, on MySQL or PostgreSQL.
`vector-ai:install` publishes the migrations into `migrations/`, or
`migrations/<name>/` with `--connection=<name>` when Vector's tables are
on another connection. Schedule `vector:jobs:reap`, then
`vector-ai:approvals:prune`, hourly.

Both admins, hosted and standalone, are `@kinetis/vector-ui`, which
carries the AI UI: restart the workers after installing, and nothing
else changes on the UI side. Full documentation:
[kinetis.dev/vector/docs/ai.html](https://kinetis.dev/vector/docs/ai.html).

## License

MIT. See [LICENSE](LICENSE).
