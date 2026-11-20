# AI

`kinetis/vector-ai` adds AI to the admin: plain-language filters, an
in-admin assistant, and writing help in text fields. It is a companion
product — the Composer package `kinetis/vector-ai` and the Nuxt layer
`@kinetis/vector-ai-ui` — built on
[`kinetis/neuron`](https://kinetis.dev/docs/neuron.html), so every
provider call suspends the calling Fiber instead of blocking the worker.

```{important}
This release offers **plain-language filters** ({ref}`ai-filters`), on
the foundations every feature shares: the operator configuration, the
sealed provider keys, the `ai` settings group, the access policy, the
model boundary, the usage ledger and the AI layer's provider-key panel.
The assistant and writing help arrive in later releases.
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

The standalone admin is on another origin, so add `Retry-After` to your
`CorsMiddleware`'s `exposedHeaders`: a browser hides any header CORS does
not expose, and without it the admin can say only that today's budget is
spent, not when it renews.

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

(ai-boundary)=
## What a model is sent

`Kinetis\VectorAi\Governance\ModelBoundary` is the one place that
decides it, for every feature. Some fields are never sent, whatever is
configured: password, file and image fields, and every field the
user's `viewField` policy hides from them (the resource is read as they
see it). Beyond those, by default a feature may send the model a
resource's fields, except those a resource or field opts out of with
the metadata bag ({ref}`resource-meta`):

```php
#[Resource(entity: Customer::class, meta: ['ai.send' => false])]   // nothing of this resource
Email::make('email')->meta('ai.send', false),                     // nor this field
```

The *Record data sent to the model* setting switches to an allow-list:
then only a resource that opts in with `meta: ['ai.send' => true]`, and
within it only the fields that opt in with `meta('ai.send', true)`, are
sent. Vector publishes the bag and never reads it; `kinetis/vector-ai`
does. A resource kept from the model whole offers no AI at all: its
plain-language filter answers `403`, and the admin hides it.

(ai-filters)=
## Plain-language filters

An admin describes the records they want — *orders over $500 from last
week that haven't shipped* — in a field with a sparkle icon beside a
list's search. The answer shows as the filter bar's own chips, with one
sentence saying what it keeps; the admin edits them like any filter,
then applies or discards them. Nothing reaches the list until it is
applied, and then through the ordinary list request, which validates
it again.

`POST /vector/ai/filters/{resource}` (in the `vector` middleware group):

```json
{ "text": "orders over $500 from last week that haven't shipped", "timezone": "Europe/Berlin",
  "current": { "filter": { "status": { "ne": "cancelled" } } } }
```

`text` is required, at most 500 characters; `timezone`, an IANA zone the
sentence's dates are read in, defaults to UTC — anything else, an offset
such as `+02:00` or an abbreviation such as `CET` included, is read as
UTC too; `current.filter`, the
list's filter now in the list grammar, is context, cut to the fields the
model may be told of. The body is at most 16 KiB. The answer is the
list's own grammar:

```json
{ "filter": { "status": { "nin": "shipped,delivered" }, "totalCents": { "gt": "50000" },
              "placedAt": { "between": "2026-11-09,2026-11-15" } },
  "groups": [], "q": null,
  "explanation": "Orders placed 9 to 15 November with a total above 500.00 whose status is not shipped or delivered.",
  "unresolved": [ { "field": "customer", "op": "eq", "text": "Acme",
                    "candidates": [ { "id": 4, "label": "Acme GmbH" }, { "id": 9, "label": "Acme Ltd" } ] } ] }
```

`filter` holds the conditions that must all hold; `groups` the "any of"
groups, each a list of alternatives, as the filter bar keeps them; `q`
the free-text search. A relation the sentence names in words is looked
up the way the filter bar's choice list is, under the user's scope: one
match becomes its id, several become an `unresolved` entry whose
condition waits for the admin to pick a candidate, none is a `422`.

The model is told the resource as {ref}`the boundary <ai-boundary>`
lets it be told — per field its name, label, type, filter operators,
choices, and how a date or money value is written — and today's date in
the admin's zone, and asked, in one structured call, for conditions
(`{field, op, value}`), "any of" groups, `q` and an explanation. The
schema names the fields and operators, but not per field: the list's
validator is the gate. The answer is put through
`ListRequest::fromQueryParams()`, as the list and `vector_query` are. A
field the model was not told of is refused before that — the refusal
names only the fields it may use — and an operator the field does not
admit is refused there; either way the model gets **one** repair turn
quoting the exact reason. A second refusal is the admin's `422`.

| Status | When |
|---|---|
| `404` | no such resource |
| `403` | the user may not list it, it is kept from the model, the feature is off, unconfigured or the daily budget is 0, or the access policy's `canUse()` refuses |
| `422` | the answer cannot be made valid after one repair (a lookup on a relation whose records the user may not list is such an answer), a lookup matches nothing, or an ambiguous lookup sits inside an "any of" group |
| `429` | today's token budget is spent; `Retry-After` counts the seconds to midnight UTC |
| `502`–`504` | the provider failed: the failure's category and status ({ref}`below <ai-failures>`) |

Each provider call — the repair turn too — is a row of the usage ledger,
with the feature `filters`. A call gets 30 seconds, or
`VECTOR_AI_TIMEOUT` when that is shorter (a Bedrock call keeps its
signing client's bound), and is never retried. Neither the sentence, the
prompt, the answer nor a provider's error body is logged.

## What the admin is told

`GET /vector/schema` carries, per user, `capabilities.ai`:

```json
{ "filters": true, "assistant": false, "assistantWrites": false, "writing": false, "excluded": { "profiles": true } }
```

A feature is `true` when it ships in the installed release, a provider
is configured, its switch is on, the access policy lets this user use
it, their daily budget has tokens left, and a provider, model and key
resolve for it. This release ships `filters`. `excluded` names each
resource this user may list that is kept from the model whole, so the
admin offers no AI on it; it is empty while no feature is offered. The key is absent when `kinetis/vector-ai` is not installed; it
is published by a shell contributor ({ref}`shell-contributors`).

## Usage and failures

Every call is recorded in `vector_ai_usage`: the actor and the
impersonator, the feature, the provider and model, the tokens in and
out, the duration, the tool calls and the outcome. The daily budget is
summed from it per actor since midnight UTC. The numbers come from each
answer, never from a counter kept in the worker.

(ai-failures)=
A failed call is reported by category — `timeout`, `too_large`,
`refused`, `unavailable` or `invalid_request` — with a status, and a
message that quotes neither the prompt, the provider's answer nor a key.
It is logged with the category and the provider's status only: a
provider's error body can quote the prompt, and its request carries the
key.

## Testing

The `fake` provider type replays replies keyed by the SHA-256 of the
last text block of the last user message — for a plain-language filter,
the admin's sentence, and for its repair turn, that turn's text — for a
chat, a stream or a structured call alike. Bind the replies on AppScope
in a test; give them a `ScriptedCalls` to read every request the
providers were sent, structured schema included:

```php
use Kinetis\VectorAi\Testing\ScriptedCalls;
use Kinetis\VectorAi\Testing\ScriptedReplies;

$calls = new ScriptedCalls();
$app->instance(ScriptedReplies::class, ScriptedReplies::of([
    'products low on stock' => '{"conditions":[{"field":"stock","op":"lte","value":"5"}],"anyOf":[],"q":null,"explanation":"Stock of 5 or less."}',
], $calls));
```

with `VECTOR_AI_PROVIDERS=fake` and `VECTOR_AI_FAKE_TYPE=fake` in the
test's configuration.

## See also

- {doc}`deployment` — the hosted and standalone modes.
- {doc}`customizing-the-ui` — the UI extension points the layer is built on.
- {doc}`settings` — settings groups.
