# Setup

Installing `kinetis/vector-ai`, then configuring its providers,
settings and keys.

(ai-installation)=
## Installation

The package needs `ext-sodium`.

1. Install the package and its tables:

   ```console
   composer require kinetis/vector-ai
   vendor/bin/kinetis vector-ai:install      # the credentials, usage, approvals and rewrites tables
   vendor/bin/kinetis migrate
   ```

   `vector-ai:install` publishes four migrations, `vector_ai_credentials`,
   `vector_ai_usage`, `vector_ai_approvals` and `vector_ai_rewrites`, to
   `migrations/`, or to `migrations/<name>/` with `--connection=<name>`,
   like `vector:install`. Put them on Vector's own connection
   (`VECTOR_DB_CONNECTION`); the command warns when they differ.

2. Restart the workers. The AI UI ships in `@kinetis/vector-ui` in both
   modes ({doc}`/deployment`); a hosted admin gets it from the bundle
   `vector:install-ui` installs, so rerun that after upgrading
   `kinetis/vector`. It shows once the API publishes `capabilities.ai`.

3. Standalone only: add `Retry-After` to your `CorsMiddleware`'s
   `exposedHeaders`, or the admin, on another origin, cannot say when a
   spent daily budget renews.

4. Schedule `vector-ai:approvals:prune` hourly, after
   `vector:jobs:reap`: nothing else deletes stale proposals or a bulk
   rewrite's rows ({ref}`ai-prune`).

5. Configure a provider ({ref}`ai-operator`).

Against a backend without `kinetis/vector-ai`, the admin shows no AI
feature and sends no AI request.

(ai-operator)=
## Operator configuration

The operator lists the providers and configures each under its own
keys, read once at boot. A value that cannot work stops the boot,
naming its key.

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

`<NAME>` is the provider's name in upper case, with a dash as an
underscore: `local-llm`'s type is `VECTOR_AI_LOCAL_LLM_TYPE`. The keys
are scoped like a named connection's (`Config::scopedKey()`).

| Type | Talks to | Key |
|---|---|---|
| `openai` | `https://api.openai.com/v1`, or the `BASE_URL` given (`https://` only) | required |
| `anthropic` | `https://api.anthropic.com/v1` only | required |
| `openai-like` | an OpenAI-compatible server at `BASE_URL` (`https://`, or `http://` for one you run) | optional |
| `ollama` | an Ollama server at `BASE_URL` (`http://` allowed) | none |
| `bedrock` | Amazon Bedrock in `REGION`, signed with the default AWS credential chain | none |
| `fake` | nothing: replays scripted replies, for tests and demos ({ref}`ai-testing`) | none |

The boot stops on an unknown or missing type; a `BASE_URL` that is not
`https://` where `http://` is not allowed, or that holds credentials; a
key for a type that takes none; a Bedrock provider without a region; and
a malformed `VECTOR_AI_KEY`.

Each worker builds one HTTP client per provider origin, pinned to its
base URL and bounded by `VECTOR_AI_TIMEOUT` and
`VECTOR_AI_MAX_RESPONSE_BYTES`, and one signing client per Bedrock
region. Parallel tool calls are never turned on: they would fork the
worker.

## The AI settings

The **AI assistant** group on the Settings page holds what an
administrator decides, under the access policy's `canConfigure()`
({ref}`ai-access`).

| Setting | Default | |
|---|---|---|
| Plain-language filters, Assistant, Writing help | on | each feature's switch |
| Assistant may propose changes (`assistantWrites`) | off | each proposal waits for a person ({ref}`ai-approvals`) |
| Provider and model per feature | empty | a provider `VECTOR_AI_PROVIDERS` lists and a model it allows; empty means the only provider and its first model |
| Daily token budget per user | 200000 | input and output tokens a day (UTC); 0 turns AI off |
| Tool calls per assistant run (`maxToolCallsPerRun`) | 8 | 1 to 20; a proposal counts as one |
| Minutes a proposed change waits for approval (`approvalMinutes`) | 15 | 1 to 120 |
| Record data sent to the model | every field | or only fields marked to send ({ref}`ai-boundary`) |

Saving refuses an unconfigured provider, and a model off its provider's
allow-list. API keys are not settings, which are stored in plain text
and served to the page; they have their own store ({ref}`ai-keys`).

(ai-keys)=
## Provider keys

An `openai` or `anthropic` provider needs an API key, and an
`openai-like` provider may take one; `bedrock`, `ollama` and `fake`
take none. Each provider gets its key one of two ways.

### The operator sets it in the environment

When whoever deploys the server holds the key, put it beside the
provider's other settings:

```ini
VECTOR_AI_PROVIDERS=openai
VECTOR_AI_OPENAI_TYPE=openai
VECTOR_AI_OPENAI_KEY=sk-...
```

It is read at boot, and its status reports it as managed by the
environment: an administrator cannot replace or remove it.

### An administrator enters it in the admin

When the key belongs to whoever runs the admin, it is stored encrypted
with `VECTOR_AI_KEY`, an environment secret, so a database copy alone
reveals no key.

1. Generate `VECTOR_AI_KEY` once:

   ```console
   php -r 'echo "base64:", base64_encode(random_bytes(32)), PHP_EOL;'
   ```

2. Add it to the environment, and list the provider without a key:

   ```ini
   VECTOR_AI_KEY=base64:...
   VECTOR_AI_PROVIDERS=openai
   VECTOR_AI_OPENAI_TYPE=openai
   ```

3. Restart the application.

4. An administrator enters the key in the Settings page's AI group.

Without `VECTOR_AI_KEY`, saving a key answers `403`, "Set VECTOR_AI_KEY
to store provider keys". Keep `VECTOR_AI_KEY` with your other secrets
and never change it: a key stored under one does not open under another,
so after a change every stored key is reported as *sealed with another
key; re-enter it*. With `VECTOR_AI_KEY` removed, stored keys are
reported as present but unusable until it is set back.

### How a stored key is kept

- It is sealed with libsodium (AEAD XChaCha20-Poly1305), with a fresh
  random nonce per write and the provider's name as additional data, so
  it does not open in another provider's row.
- It is stored in `vector_ai_credentials` and never shown again, by any
  endpoint, page, error or log line.
- Each row records the id of the key that sealed it (the first 8 hex
  characters of its SHA-256), so a key sealed under another
  `VECTOR_AI_KEY` is recognized rather than misread.
- Storing and removing one are audited ({doc}`/audit`) as resource
  `vector-ai:credentials`, the provider's name, the event `set` or
  `delete`, and the actor (and impersonator), never the key.

### The key endpoints

These routes, all behind the access policy's `canConfigure()`, manage
stored keys:

| Route | Does |
|---|---|
| `GET /vector/ai/credentials` | Lists each configured provider's key status (below). Never the key. |
| `PUT /vector/ai/credentials/{provider}` | Seals and stores `{"key": "..."}`. `403` without `VECTOR_AI_KEY`, `404` for an unknown provider, `409` for one the environment manages or that takes no key. |
| `DELETE /vector/ai/credentials/{provider}` | Removes the stored key. |

Each status carries `set`, `managedBy` (`environment`, `admin`, or
`null` for a type that takes no key), `storable`, for a stored key
`updatedAt` and `updatedBy`, and for an unusable one `problem`. Beside
it, `sealedWithAnotherKey` is `true` when another `VECTOR_AI_KEY` sealed
the key, and absent when the server has no `VECTOR_AI_KEY` (`storable`
is then `false`).
