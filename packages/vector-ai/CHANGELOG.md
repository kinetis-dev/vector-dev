# Changelog

All notable changes to `kinetis/vector-ai` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.0] - 2026-11-26

### Changed

- Requires `kinetis/vector` ^0.19.

## [0.3.0] - 2026-11-25

### Changed

- Requires `kinetis/vector` ^0.18.
- Requires `kinetis/query-builder` ^1.8.6.
- Requires `kinetis/framework` ^1.32.0 and `kinetis/mcp` ^1.10.0. A
  proposal's arguments are checked as the MCP dispatcher of that release
  checks them.
- The AI settings' `dataDefault` is a `Select` field, kinetis/vector
  having retired `Enum`; its options and default are unchanged.

## [0.2.2] - 2026-11-23

### Changed

- Requires `kinetis/framework` ^1.31.0.

## [0.2.1] - 2026-11-23

### Changed

- Requires `kinetis/neuron` ^1.3.4 and `neuron-core/neuron-ai` ^4.1.28.

## [0.2.0] - 2026-11-23

### Added

- The assistant has `vector_aggregate` among its reads, governed like
  the others: refused when its `groupBy`, a metric, its `filter`, `sort`
  or `q` touches a field kept from the model (a money metric's currency
  field included), its answer sent after the preamble through
  `ModelBoundary::toolResult()`, with a belongsTo key's label left out
  when the related resource's title field may not be sent. The system
  prompt tells the model to aggregate rather than add up or count rows,
  and to list with `fields`.

- Bulk rewrite proposals: `Actions\ProposeRewrites`, a queued Vector
  action a resource opts into by listing a subclass (naming the field
  and a `WritingOperation`, with optional `targetLocale()` and
  `instructions()`) in its `actions()`. It runs writing help's rules as
  the admin who started it (a misconfigured run, including a field not
  on the detail view, fails before any provider call, and one that stops
  being available after a chunk has run sends no more (`unavailable`)
  but still delivers its CSV), makes one single-shot writing call per
  record, skips and counts a record they may not update or with no text,
  counts a failed call by category whatever it throws and an empty
  answer as `empty_answer`, stops calling once the day's budget is spent
  (`budget`) or the proposals near the job file's size
  (`ProposeRewrites::FILE_LIMIT`, `file_full`), and leaves the job one
  CSV, `<id field>,<field>`, of the proposals to review and import in
  update mode keyed by ID. It writes no record. Each record's outcome is
  kept in the new `vector_ai_rewrites` table (migration
  `20261121000101_vector_ai_rewrites`) by (job, record), so a chunk
  delivered again makes no provider call; they outlive the saving of the
  CSV on the job, and `vector-ai:approvals:prune` deletes them once the
  job has ended (schedule `vector:jobs:reap` before it). A proposal or
  record ID ending in a backslash, which the importer cannot read back,
  is left out of the CSV and counted as skipped. Each chunk declares the
  records it proposed for (`ActionResponse::withRecords()`), so only
  those are audited and `affected` counts proposals. Every chunk reads
  the `ai` settings anew (Vector runs each on a scope of its own), so a switch-off
  saved while the job runs stops the next chunk. The CSV is never larger
  than `JobStore::MAX_FILE_BYTES`: the early stop counts rows as the CSV
  writes them (quotes doubled, `ProposeRewrites::rowBytes()`), and
  `finish()` adds whole rows only while they fit, counting a proposal
  that did not in the job's message.
- `Auth\AiAvailability`: whether this request's user may use each AI
  feature (`permits()`, `offered()`, `provider()`, and for
  `capabilities.ai` `availableNow()`, which adds the day's budget and
  whether a key is there) and the `429` a spent daily budget is answered
  with (`budgetSpent()`, the one place every AI endpoint's `429` comes
  from; `remaining()` for `ProposeRewrites`), asked by every AI endpoint,
  the writing guard, `ProposeRewrites`, the assistant and `capabilities.ai`
  instead of a copy of the check in each.
- `Writing\WritingGuard`: writing help's field, permission, boundary
  and provider checks, shared by the writing endpoint and
  `ProposeRewrites`; the budget is `AiAvailability`'s, asked by both
  directly.
- `Testing\ScriptedReplies` can make the `fake` provider throw for a
  message (`of()`'s `$failures`, `failure()`), as a failing provider
  call would.
- The `ai.writing` field meta (`WritingGuard::META_KEY`):
  `->meta('ai.writing', false)` turns writing help off for a field, and
  `true` turns it on for a unique string. It is independent of
  `ai.send`: a field with writing help off is still sent to the model.
- `capabilities.ai.noWriting`, `{slug: [field, …]}` like
  `excludedFields`: per resource the user may list and the model may be
  sent, the `string`, `text`, `markdown` and `richtext` fields they see
  that writing help is not offered on (`WritingGuard::offers()`). The
  admin reads both; the endpoint refuses those fields either way.

### Changed

- `vector_query`'s page size follows its projection: 25 rows without
  `fields`, and with n fields at most min(200, ⌊1000 / n⌋)
  (`ModelBoundary::maxRows()`), for `perPage` and for the rows the
  boundary sends. A projection naming a field kept from the model is
  refused.

- Writing help is off by default for a `string` field marked
  `->unique()`: a unique short string is an identifier (a SKU, a code),
  not prose. `WritingGuard::helps()` decides it (with the `ai.writing`
  override), and the writing endpoint and `ProposeRewrites` refuse such a
  field with the `403` they answer for any other field that is no text
  (*Writing help is off for this field of …*).
- Under `APP_ENV=development` only, a failed run's log line also carries
  the exception's message as a plain string, `exception_message`, since
  a log formatter may print the attached exception's class alone. No
  other environment logs the message, and the client still gets only
  "The run failed."
- `ProposeRewrites::csvWithin()` throws
  `Kinetis\VectorAi\Actions\CsvStreamException`, which extends
  `\RuntimeException`, when it cannot open its memory stream: the message
  is unchanged and every existing catch still applies. The methods
  SonarQube found too complex (the assistant's and writing help's body
  checks, the assistant's run, the filter translator, the model
  boundary's write failures, aggregates and widgets, the bulk rewrites,
  the settings group, tool arguments, the governed tool and
  `vector-ai:install`) delegate to private helpers, with every check,
  message and its order, output and exit code unchanged.

### Fixed

- A projected `vector_query` naming no `perPage` is given at most its resource's own `maxPerPage()`, not a size the list would refuse.

- A model's reasoning written inline is no longer read as its answer:
  what Amazon Nova and Claude 3 write between `<thinking>` and
  `</thinking>`, and DeepSeek R1, Qwen3 and QwQ between `<think>` and
  `</think>`, is taken out by kinetis/neuron's `InlineReasoningProvider`,
  which `ProviderResolver` wraps every provider it builds in, the `fake`
  one included. So the assistant's streamed `text-delta` frames and its
  whole answer's `text`, a filter's structured answer and writing help's
  text are all the answer alone. The assistant writes no reasoning part
  to the browser, so reasoning is neither shown nor sent back with the
  conversation, and it still counts toward the usage estimate of a call
  the browser left or that failed.

- A proposal left `executing` by a request that never finished is no
  longer stuck: `ApprovalStore::failStale()` marks it `failed` with *The
  outcome is unknown: the request that ran it did not finish. Check the
  record's History.* once it has been executing for ten minutes
  (`ApprovalStore::EXECUTING_MINUTES`), from `vector-ai:approvals:prune`
  and from an approve or reject of that row, which then answers 409
  `failed` instead of `executing`. It is never executed again.
- An approval that threw is recorded as *The change failed, but it may
  have been made before it did. Check the record's History.* (the
  write may have committed) and the assistant tells the person the
  outcome is unknown.
- The conversation sent to the provider alternates roles: a fixed
  assistant turn goes between two adjacent user-role turns (an answer
  that only proposed, then the decision), which Bedrock refused. The
  drawer also keeps such an answer, with placeholder text.
- A pending proposal past its expiry cannot be approved or rejected
  even between the expiry check and the update: the update asks it too.
- An approval of a row whose tool is not one of the four writes is
  `failed` without dispatching anything.
- A proposal Vector's own permissions would refuse the person (`create`,
  or the record's `update` or `delete`) is refused when it is proposed,
  instead of showing a card the approval would only fail.
- A conversation that opens with the assistant (as one the browser
  trimmed from its start can) has its turns before the first user-role
  one dropped before it is sent to the provider, which Bedrock otherwise
  refused.
- `capabilities.ai.assistantWrites` is offered only to someone the
  access policy lets use the assistant too, as the approve endpoint
  requires; it no longer advertises proposals the server would refuse.
- `GET /vector/schema` no longer builds an AI provider or opens a stored
  key for each feature on every request: `ProviderResolver::resolvable()`
  decides from the configuration, the settings and the stored key's
  record (`CredentialStore::stored()`). The flags are the same for a
  configured and an unconfigured provider.
- `ProposeRewrites` no longer makes two queries per record: a chunk
  reads which of its records are done (`RewriteStore::doneAmong()`,
  replacing `done()`) and today's budget left once, and counts each
  call's tokens (`WritingAssistant::write()`'s new `$tokens` out
  parameter) and each outcome in memory. The budget still stops the run
  within a chunk, and a switch-off or another request's spending shows
  from the next chunk.
- `ModelBoundary` reads each resource as the user sees it once, for the
  life of the boundary (one request, or one chunk of a queued action,
  which Vector now runs on a request scope of its own, so a field hidden
  mid-run is hidden in the next chunk), rather than once
  per row, search column and reference: a page of 25 rows asks the
  `viewField` policy once per field instead of 25 times.
- The model is no longer sent the text of a refusal that failed an
  approved change: a validation, policy or hook message, which can
  quote record values and fields kept from it (`ai.send` false). It is
  told `ModelBoundary::writeFailure()` instead: for a validation
  refusal, which fields the boundary lets through failed and their rule
  codes, and how many others did; for any other, a fixed sentence. The
  package's own failure texts are still told as they are.
- A forced delete (`vector_delete` with `force: true`) is proposed only
  when the person's policy grants `forceDelete` on the record, asked
  through the gate as Vector's own forced delete (`RecordWriter`) asks
  it, of a live record as of a trashed one, so a live record's forced
  delete is no longer refused because the trash's
  `recordPermissions()['forceDelete']` is false for it; `delete` alone
  does not let it through, and a resource that does not soft-delete has
  none.
- A streamed assistant run the browser left is told apart from a failed
  one by an explicit rule, not by the exception's type: it ended because
  the browser left only when the application stopped it: its stream
  asked the run's `Assistant\DepartureSignal` (the stop closure
  `AssistantController` hands the provider) and was stopped, or
  `Assistant::told()` saw the connection gone between two steps and did
  not advance the run. Such a run is logged at info (`An assistant run
  ended after the browser left.`, outcome `client_gone`), never as
  `answered`, with no provider warning. Everything else (a Bedrock
  refusal, a cut stream, a tool's exception, a runaway model, a database
  error) is the run's failure, logged as one whether or not the browser
  is still there. The connection decides only whether frames are
  written: once it is gone nothing more is, and the run is advanced no
  further (a browser gone before the first step sends no request), so
  the model is not called again and nothing is proposed for nobody.
- An assistant call is charged by whether the provider produced output:
  a complete answer as reported; an answer the departure stopped with
  the input reported (or estimated when none was) and the larger of the
  reported and the streamed output; a stream stopped before any text, or
  a failure after the provider streamed anything, estimated
  (`AssistantLedger::ESTIMATE_BYTES`, 4 bytes a token of what was sent
  and streamed back, tool definitions not counted); a failure with
  nothing streamed (no connection, an HTTP or Bedrock refusal) at 0,
  however often it is retried. An estimated row's outcome ends in
  `:estimated`; each call is one row, each side the larger of reported
  and estimated, never their sum.
- A proposal whose arguments nest deeper than a stored proposal is read
  back with (`ApprovalStore::MAX_DEPTH`, 64, the one limit for both) is
  refused to the model as a tool error (`ProposingTool::TOO_DEEP`) and
  never stored, and `ApprovalStore::propose()` refuses one too: before,
  it was stored and could then never be approved or rejected.
- An approved change whose result nests deeper than that (an update of
  a record with deeply nested JSON) is stored with
  `ApprovalStore::TOO_DEEP` (`{"truncated": "too_deep"}`) as its result
  instead: the approval still settles, and the conversation that replays
  it no longer fails on every read.
- `capabilities.ai` reads the resources the descriptor already decided
  (`VisibleResources::all()`), and ModelBoundary reads a resource the
  request's `VisibleResources` already decided from it
  (`VisibleResources::decided()`): GET /vector/schema, a 304
  included, no longer asks each resource's `viewAny` and each field's
  `viewField` policy a second time for `excluded` and `excludedFields`.
- `AiShellContributor::capabilities()` answers for the user it is given:
  for the request's own user from the request's services, for anyone
  else from a request scope of their own (`Vector\Auth\ActorScope`),
  instead of always for the request's user. Building that scope is
  inside the same catch-all as the rest: a failure there turns every AI
  feature off rather than failing GET /vector/schema.
- `capabilities.ai` looks up a provider's stored key once per request
  (`ProviderResolver::resolvable()`), not once per feature: four
  features sharing one key were four identical queries on every
  GET /vector/schema, a 304 included.

### Changed

- Requires `kinetis/vector` ^0.17, whose `vector_aggregate` MCP tool the
  assistant reads with.
- Requires `kinetis/framework` ^1.30.0, whose console answers `--help` and
  `-h` on every command with its usage and description without running
  it, and `kinetis/neuron` ^1.3.3. The
  assistant learns that the browser left from the request's
  `Kinetis\Runtime\ClientConnectionInterface` instead of calling
  `connection_aborted()`, and `Assistant::stream()` takes that
  connection as its second argument.
- The assistant's whole answer (`Accept: application/json`) gives each
  of its `toolCalls` as `{"name": …, "ok": true|false}` instead of the
  bare name: `ok` is false for a call that was a tool error (a refusal,
  a validation failure, a spent budget), as the stream tells it by
  `tool-output-error`. A proposal waiting for approval answered: it is
  `ok`. `ToolBudget::calls()` and `settle()` keep each call's outcome;
  `names()` still lists the tools for the run's log line.

## [0.1.0] - 2026-11-19

### Added

- The package, with its first feature, plain-language filters:
  `POST /vector/ai/filters/{resource}` turns a sentence into the list's
  own filter grammar (`filter`, `groups`, `q`), validated by Vector's
  list validator with one repair turn, with dates read as days in the
  admin's time zone (the request's `timezone`, written with its offset),
  an `anyOf` of one alternative joined to the conditions, a relation named in words
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
  and `vector-ai:install`. The UI is part of `@kinetis/vector-ui`
  0.13.0, shown when the descriptor carries `capabilities.ai`.
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
  `ModelBoundary::record()`, `page()` and `toolResult()` decide which parts of a
  record, a page, a description or a widget are sent to a model (excluded
  fields gone, the primary key kept, a relation's label and carried
  columns (a belongsTo's or a hasOne's) only as far as its target's
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
  `{text, operation, model, truncated}` out) and writes nothing: the
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
- The assistant proposing changes, behind the `assistantWrites` setting
  and `AiAccessInterface::canUse($user, Feature::AssistantWrites)`
  (`capabilities.ai.assistantWrites`): Vector's four writes reach the
  model as `Assistant\ProposingTool`s, and not at all otherwise. A call
  writes nothing: it counts against `maxToolCallsPerRun`, has its
  arguments checked as the MCP dispatcher checks them
  (`Approvals\ToolArguments`, the same `{"errors": [...]}` feedback), is
  refused for a resource or a field the boundary keeps back, and is
  stored as a pending row of `vector_ai_approvals` (new migration;
  `Approvals\ApprovalStore`, 128-bit base32 ids shown as `apr_…`)
  expiring after `approvalMinutes`. The stream announces each proposal as
  a `data-approval` frame and the JSON answer lists them under
  `approvals`. `POST /vector/ai/approvals/{id}/approve` and `/reject`
  (`Http\ApprovalController`) decide it with an empty body: 403 when
  writes are off for the user now, 404 for another user's proposal or an
  impersonation mismatch, 410 once expired, 409 with the stored result
  once decided; approving runs the row's own arguments through
  `McpTools` as the user with the origin `assistant`, and records
  `executed` or `failed` (Vector's refusal, or only *The change failed.*
  when it threw), one log line and one usage row (`assistant-writes`).
  A conversation's `{role: "approval", id}` message tells the model the
  outcome from the row, its result through `ModelBoundary::toolResult()`,
  which now governs the write tools' results too.
  `vector-ai:approvals:prune` expires stale proposals and deletes those
  decided more than `--days` (30) ago.

### Changed

- Requires PHP 8.4 or 8.5 (`>=8.4 <8.6`), `kinetis/framework` 1.29.1,
  `kinetis/neuron` 1.3.2 and `neuron-core/neuron-ai` 4.1.27. Every other
  Kinetis requirement is raised to its latest release.
