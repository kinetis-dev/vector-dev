# AI

`kinetis/vector-ai` adds AI to the admin: plain-language filters, an
in-admin assistant, and writing help in text fields. It is a companion
product — the Composer package `kinetis/vector-ai` and the Nuxt layer
`@kinetis/vector-ai-ui` — built on
[`kinetis/neuron`](https://kinetis.dev/docs/neuron.html), so every
provider call suspends the calling Fiber instead of blocking the worker.

```{important}
This release lays the foundations only: the operator configuration, the
sealed provider keys, the `ai` settings group, the access policy, the
usage ledger and the AI layer's provider-key panel. **No AI feature is
offered yet**:
plain-language filters, the assistant and writing help arrive in later
releases. Until then the admin shows the *AI assistant* settings group
and nothing else.
```

## Installing

```console
composer require kinetis/vector-ai
vendor/bin/kinetis vector-ai:install      # the credentials and usage tables
vendor/bin/kinetis migrate
```

`vector-ai:install` publishes two migrations, `vector_ai_credentials` and
`vector_ai_usage`, the way `vector:install` publishes Vector's: into
`migrations/`, or `migrations/<name>/` with `--connection=<name>`. Put
them on the connection Vector's own tables are on
(`VECTOR_DB_CONNECTION`); the command notes it when they differ. The
package needs `ext-sodium`.

The AI layer reaches the admin in standalone mode ({doc}`deployment`):
your Nuxt app extends `@kinetis/vector-ai-ui` instead of
`@kinetis/vector-ui`: `extends: ['@kinetis/vector-ai-ui']`. Hosted
(no-build) delivery of the AI layer comes in a later release; until then
the hosted admin `vector:install-ui` installs is the plain one.

Each `@kinetis/vector-ai-ui` release depends on exactly the
`@kinetis/vector-ui` release `kinetis/vector` pins. Against a backend
without `kinetis/vector-ai`, the layer renders nothing of its own.

## Operator configuration

The operator lists the providers the admin may call, and configures each
under its own keys. Everything is read once, at boot; a value that
cannot work stops the boot with a message naming its key.

```ini
VECTOR_AI_PROVIDERS=openai,bedrock              # names: 1-40 of a-z, 0-9 and -
VECTOR_AI_<NAME>_TYPE=openai|anthropic|openai-like|ollama|bedrock|fake
VECTOR_AI_<NAME>_BASE_URL=https://...           # openai-like and ollama
VECTOR_AI_<NAME>_KEY=...                        # optional; else an administrator stores one
VECTOR_AI_<NAME>_MODELS=a,b                     # the allow-list; empty admits any
VECTOR_AI_<NAME>_REGION=eu-central-1            # bedrock
VECTOR_AI_KEY=base64:...                        # 32 bytes; without it, keys cannot be stored
VECTOR_AI_TIMEOUT=90                            # seconds per call
VECTOR_AI_MAX_RESPONSE_BYTES=4194304
VECTOR_AI_MAX_OUTPUT_TOKENS=2048
```

`<NAME>` is the provider's name in upper case, a dash written as an
underscore: `local-llm`'s type is `VECTOR_AI_LOCAL_LLM_TYPE`. The keys
are scoped the way named connections' are (`Config::scopedKey()`).

| Type | Talks to | Key |
|---|---|---|
| `openai` | `https://api.openai.com/v1`, or the `BASE_URL` given (`https://` only) | required |
| `anthropic` | `https://api.anthropic.com/v1` only | required |
| `openai-like` | an OpenAI-compatible server at `BASE_URL` (`https://`, or `http://` for one you run) | optional |
| `ollama` | an Ollama server at `BASE_URL` (`http://` allowed) | none |
| `bedrock` | Amazon Bedrock in `REGION`, signed with the default AWS credential chain | none |
| `fake` | nothing: replays scripted replies, for tests and demos | none |

The boot stops on an unknown type, a provider listed without a type, a
`BASE_URL` that is not `https://` (where `http://` is not allowed), one
with credentials in it, a key for a type that takes none, a Bedrock
provider without a region, or a malformed `VECTOR_AI_KEY`.

At boot the package builds one HTTP client per provider origin — pinned
to that base URL, bounded by `VECTOR_AI_TIMEOUT` and
`VECTOR_AI_MAX_RESPONSE_BYTES` — and one signing client per Bedrock
region, shared by every request of the worker. The provider object a
call uses is built fresh for that call, over the shared client; parallel
tool calls, which would fork the worker, are never turned on.

## The AI settings

The **AI assistant** group on the Settings page holds what an
administrator decides:

| Setting | Default | |
|---|---|---|
| Plain-language filters, Assistant, Writing help | on | each feature's switch |
| Assistant may propose changes | off | every change it proposes still waits for a person |
| Provider and model per feature | empty | a provider `VECTOR_AI_PROVIDERS` lists, and a model on its allow-list; empty uses the only provider, and its first allowed model |
| Daily token budget per user | 200000 | input and output tokens a person may spend a day (UTC); 0 turns AI off |
| Tool calls per assistant run | 8 | at most 20 |
| Minutes a proposed change waits for approval | 15 | 1 to 120 |
| Record data sent to the model | every field | or only fields marked to send; see below |

Saving refuses a provider that is not configured and a model off its
provider's allow-list. Who may manage the group is the access policy's
`canConfigure()`.

API keys are **not** settings: settings are stored, and served to the
Settings page, in plain text.

## Provider keys

A provider's key comes from the environment (`VECTOR_AI_<NAME>_KEY`) or,
when the environment gives none, from an administrator, on the Settings
page under the AI group. An administrator's key is sealed with libsodium
(AEAD XChaCha20-Poly1305) under `VECTOR_AI_KEY`, with a fresh random
nonce for every write and the provider's name as additional data, and
stored in `vector_ai_credentials`: a sealed key copied into another
provider's row does not open there. It is never shown again: no endpoint, page,
error or log line returns it. Storing and removing a key are recorded in
Vector's audit trail ({doc}`audit`) under the resource
`vector-ai:credentials` and the provider's name, with the event `set` or
`delete` and the actor (and impersonator): who changed which provider's
key, never the key.

Generate `VECTOR_AI_KEY` once, and keep it with your other secrets:

```console
php -r 'echo "base64:", base64_encode(random_bytes(32)), PHP_EOL;'
```

Without it, an administrator cannot store keys (the endpoint answers
`403`, "Set VECTOR_AI_KEY to store provider keys"); keys from the
environment still work. Each sealed row records the id of the key that
sealed it (the first 8 hex characters of its SHA-256), so after
`VECTOR_AI_KEY` changes, a row sealed under the old one is reported as
*sealed with another key; re-enter it*, never silently misread. A row
stored before `VECTOR_AI_KEY` was removed is reported as present but
unusable until it is set again.

| Route | Does |
|---|---|
| `GET /vector/ai/credentials` | Each configured provider: `set`, `managedBy` (`environment`, `admin`, or `null` for a type that takes no key), `storable`, and for a stored key `updatedAt`, `updatedBy`, and — when it cannot be used — `problem`: `sealedWithAnotherKey` is `true` when it was sealed with another `VECTOR_AI_KEY`, and absent when the server has no `VECTOR_AI_KEY` to open it (`storable` is then `false`). Never the key. |
| `PUT /vector/ai/credentials/{provider}` | `{"key": "..."}`: seals and stores it. `403` without `VECTOR_AI_KEY`, `404` for an unknown provider, `409` for one the environment manages or that takes no key. |
| `DELETE /vector/ai/credentials/{provider}` | Removes the stored key. |

All three are in the `vector` middleware group and ask the access
policy's `canConfigure()`.

## Who may use AI

`Kinetis\VectorAi\Auth\AiAccessInterface` decides who may use each
feature (`canUse($user, Feature $feature)`) and who may configure AI
(`canConfigure($user)`). The default admits everyone the Vector access
policy admits. Bind your own on AppScope, as you bind
`VectorAccessInterface`; it holds no request state.

```php
$app->instance(AiAccessInterface::class, new MyAiAccess());
```

## What a model is sent

By default a feature may send the model a record's fields, except those
a resource or field opts out of with the metadata bag
({ref}`resource-meta`):

```php
#[Resource(entity: Customer::class, meta: ['ai.send' => false])]   // nothing of this resource
Email::make('email')->meta('ai.send', false),                     // nor this field
```

The *Record data sent to the model* setting switches to an allow-list:
then only what opts in with `meta('ai.send', true)` is sent. Vector
publishes the bag and never reads it; `kinetis/vector-ai` does. Which
resources and fields are kept from the model is published per user as
`capabilities.ai.excluded`, once a feature sends record data.

## What the admin is told

`GET /vector/schema` carries, per user, `capabilities.ai`:

```json
{ "filters": false, "assistant": false, "assistantWrites": false, "writing": false, "excluded": {} }
```

A feature is `true` when it ships in the installed release, a provider
is configured, its switch is on, the access policy lets this user use
it, their daily budget has tokens left, and a provider, model and key
resolve for it. In this release no feature ships, so every flag is
`false`. The key is absent when `kinetis/vector-ai` is not installed; it
is published by a shell contributor ({ref}`shell-contributors`).

## Usage and failures

Every call is recorded in `vector_ai_usage`: the actor and the
impersonator, the feature, the provider and model, the tokens in and
out, the duration, the tool calls and the outcome. The daily budget is
summed from it per actor since midnight UTC. The numbers come from each
answer, never from a counter kept in the worker.

A failed call is reported by category — `timeout`, `too_large`,
`refused`, `unavailable` or `invalid_request` — with a status, and a
message that quotes neither the prompt, the provider's answer nor a key.

## Testing

The `fake` provider type replays replies keyed by the SHA-256 of the
last user message and records every request it saw. Bind the replies
on AppScope in a test:

```php
use Kinetis\VectorAi\Testing\ScriptedReplies;

$app->instance(ScriptedReplies::class, ScriptedReplies::of(['Show low stock' => 'stock lte 5']));
```

with `VECTOR_AI_PROVIDERS=fake` and `VECTOR_AI_FAKE_TYPE=fake` in the
test's configuration.

## See also

- {doc}`deployment` — the hosted and standalone modes.
- {doc}`customizing-the-ui` — the UI extension points the layer is built on.
- {doc}`settings` — settings groups.
