# Changelog

All notable changes to `kinetis/vector-ai` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-19

### Added

- The package, with its first feature, plain-language filters:
  `POST /vector/ai/filters/{resource}` turns a sentence into the list's
  own filter grammar (`filter`, `groups`, `q`), validated by Vector's
  list validator with one repair turn, with a relation named in words
  looked up under the user's scope (`unresolved` when several records
  match), a 30-second deadline per provider call, and a usage row per
  call; `Governance\ModelBoundary`, the one place that decides what a
  model is told (never password, file or image fields or what
  `viewField` hides; `ai.send` metadata opts a resource or field out, or
  in under the `allowlist` setting); `capabilities.ai.filters` on, and
  `capabilities.ai.excluded` naming the resources kept from the model.
  The foundations under it: `AiConfig` (the operator's
  `VECTOR_AI_*` configuration, checked at boot), `AiTransports` (one
  `NeuronHttpClient` per provider origin, one `SigV4SigningClient` per
  Bedrock region), `SecretBox` and `CredentialStore` (provider keys
  sealed under `VECTOR_AI_KEY` for their provider, and `GET`, `PUT` and
  `DELETE /vector/ai/credentials`, which record each `set` and `delete`
  in Vector's audit trail without the key), the `ai` settings group, `AiAccessInterface`,
  `ProviderResolver`, the `ai` shell contributor (every feature off whenever computing it fails),
  `AiFailure` and `AiFailureMapper` (a provider failure by category and
  status, logged with those two only), the `UsageLedger`,
  `Testing\ScriptedProvider` (the `fake` provider type, chat, stream and
  structured calls alike, with `ScriptedCalls` to read what it was sent)
  and `vector-ai:install`. The UI half,
  `@kinetis/vector-ai-ui`, is delivered through Nuxt `extends`; hosted
  (no-build) delivery of it comes in a later release.
- The read-only assistant: `POST /vector/ai/assistant` runs one agent
  turn over Vector's five read tools (`vector_resources`,
  `vector_describe`, `vector_query`, `vector_get`, `vector_widget`) as
  the request's user, streamed in the Vercel AI SDK data-stream protocol
  (`Kinetis\Neuron\AgentStream`) or, with `Accept: application/json`,
  answered whole as `{text, toolCalls}`. The conversation is the
  browser's, replayed on every turn (at most 40 messages, 64 KiB). Every
  tool call is an `Assistant\GovernedTool`: `vector_query` clamped to 25
  rows and 20 pages, a resource kept from the model or one the user may
  not list (`viewAny`) refused as a tool error and left out of the
  system prompt, a `vector_query` whose `filter` (`or` and `and` groups
  included) or `sort` names a field kept from the model, or whose `q`
  would search one, refused as a tool error that does not name the
  field, and a query naming no sort ordered by the default sort without
  such fields (`ModelBoundary::sends()`, `searches()` and
  `defaultSort()`, which `vector_describe`'s answer follows too), at most `maxToolCallsPerRun` calls, every answer cut by the
  boundary and sent after a fixed data-not-instructions preamble.
  `ModelBoundary::record()`, `page()` and `toolResult()` decide what of a
  record, a page, a description or a widget a model is sent (excluded
  fields gone, the primary key kept, a relation's label and carried
  columns — a belongsTo's or a hasOne's — only as far as its target's
  fields may be sent, strings cut at
  2,000 characters, at most 25 rows; a widget whose link leads to a
  resource kept from the model refused, and a table's row keys and
  columns that are fields its linked resource keeps back left out). One deadline per provider call (`VECTOR_AI_TIMEOUT`), a
  stream stopped once the browser leaves, one usage row per provider
  call, one log line per run without its content, and a mid-stream
  failure told by the neutral error frame and logged by category (a
  warning for a provider's failure, an error for the run's own:
  `error`, `runaway_tools`);
  `capabilities.ai.assistant` on. `Testing\ScriptedProvider` scripts
  tool-calling turns (`ScriptedReplies::of()` with a list of turns).
- Writing help: `POST /vector/ai/writing/{resource}/{field}` rewrites,
  shortens, corrects or translates one field's text
  (`{operation, text, format, targetLocale, instructions, recordId}` in,
  `{text, operation, model, truncated}` out) and writes nothing — the
  admin puts the answer in the input for the person to save as their own
  edit. A password field is refused by its type before anything else;
  the field must be a `string`, `text`, `markdown` or `richtext` field
  this user may write in the view the request is for (create without a
  record, update with one, decided by `recordPermissions()['update']`),
  one `ModelBoundary` lets a model be sent, with the feature on,
  configured and the user's, and today's budget left (429). Each
  operation has a fixed system prompt (`Writing\WritingOperation`); the
  person's guidance (at most 300 characters) and their text, delimited
  and labelled as content, go in the user turn. The answer is stripped
  of control characters but `\n` and `\t`, and cut at the field's
  maximum length, or four times the input up to 20,000 characters, with
  `truncated` set (`Writing\WritingAssistant`). One usage row per call,
  feature `writing`; `capabilities.ai.writing` on.
  `capabilities.ai.excludedFields` names, per resource the user may list
  that is not kept back whole, the fields the boundary keeps back;
  `excluded` keeps its shape.
