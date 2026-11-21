# Changelog

All notable changes to `kinetis/vector-ai` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Bulk rewrite proposals: `Actions\ProposeRewrites`, a queued Vector
  action a resource opts into by listing a subclass (naming the field
  and a `WritingOperation`, with optional `targetLocale()` and
  `instructions()`) in its `actions()`. It runs writing help's rules as
  the admin who started it — a misconfigured run, including a field not
  on the detail view, fails before any provider call, and one that stops
  being available after a chunk has run sends no more (`unavailable`)
  but still delivers its CSV — makes one single-shot writing call per
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
  the `ai` settings anew (`AiSettingsReader::refresh()`), so a switch-off
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

### Fixed

- A proposal left `executing` by a request that never finished is no
  longer stuck: `ApprovalStore::failStale()` marks it `failed` with *The
  outcome is unknown: the request that ran it did not finish. Check the
  record's History.* once it has been executing for ten minutes
  (`ApprovalStore::EXECUTING_MINUTES`), from `vector-ai:approvals:prune`
  and from an approve or reject of that row, which then answers 409
  `failed` instead of `executing`. It is never executed again.
- An approval that threw is recorded as *The change failed, but it may
  have been made before it did. Check the record's History.* — the
  write may have committed — and the assistant tells the person the
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
- A conversation that opens with the assistant — as one the browser
  trimmed from its start can — has its turns before the first user-role
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
  approved change — a validation, policy or hook message, which can
  quote record values and fields kept from it (`ai.send` false). It is
  told `ModelBoundary::writeFailure()` instead: for a validation
  refusal, which fields the boundary lets through failed and their rule
  codes, and how many others did; for any other, a fixed sentence. The
  package's own failure texts are still told as they are.
- A forced delete (`vector_delete` with `force: true`) is proposed only
  when the person's policy grants `forceDelete` on the record, asked
  through the gate as Vector's own forced delete (`RecordWriter`) asks
  it — of a live record as of a trashed one, so a live record's forced
  delete is no longer refused because the trash's
  `recordPermissions()['forceDelete']` is false for it; `delete` alone
  does not let it through, and a resource that does not soft-delete has
  none.
- A streamed assistant run that fails after the browser left writes no
  error frame to the closed connection; once the request's
  `ClientConnectionInterface` reports the browser gone, nothing more of
  the run is written, and the run is read no further: the model is not
  called again, and no tool runs and nothing is proposed for nobody to
  see (`Assistant::told()` asks the connection before it advances the
  run). The stop the departure caused — the provider's
  stream stopped or cut before the answer ended, a Neuron
  `ProviderException` — is logged at info (`An assistant run ended after
  the browser left.`, outcome `client_gone`), and so is a run the browser
  left that ended cleanly, as a stopped stream does, rather than as
  `answered`; any other failure — a
  tool's bug, a runaway model, a database error — is still logged as the
  run's failure, at error level when it is the run's own.
- An assistant call that ends with no tokens reported — a stream stopped
  because the browser left, whose usage never arrives, one cut or failed
  without an HTTP status, or one still in flight when the run was
  abandoned — counts against the daily budget with an estimate
  (`AssistantLedger::ESTIMATE_BYTES`, 4 bytes a token, of what was sent
  and streamed back), its usage row's outcome marked `:estimated`;
  before, it cost nothing. Reported usage is recorded as it is, never
  estimated as well; a call the provider refused with an HTTP status
  stays at 0, and a stopped stream's row is `client_gone`, not
  `answered`.
- A proposal whose arguments nest deeper than a stored proposal is read
  back with (`ApprovalStore::MAX_DEPTH`, 64, the one limit for both) is
  refused to the model as a tool error (`ProposingTool::TOO_DEEP`) and
  never stored, and `ApprovalStore::propose()` refuses one too: before,
  it was stored and could then never be approved or rejected.
- `capabilities.ai` reads the resources the descriptor already decided
  (`VisibleResources::all()`), and ModelBoundary takes their descriptors
  as the user sees them (`rememberVisible()`): GET /vector/schema, a 304
  included, no longer asks each resource's `viewAny` and each field's
  `viewField` policy a second time for `excluded` and `excludedFields`.
- `AiShellContributor::capabilities()` answers for the user it is given:
  for the request's own user from the request's services, for anyone
  else from a request scope of their own, instead of always for the
  request's user.
- `capabilities.ai` looks up a provider's stored key once per request
  (`ProviderResolver::resolvable()`), not once per feature: four
  features sharing one key were four identical queries on every
  GET /vector/schema, a 304 included.

### Changed

- Requires `kinetis/framework` ^1.29.0 and `kinetis/neuron` ^1.2. The
  assistant learns that the browser left from the request's
  `Kinetis\Runtime\ClientConnectionInterface` instead of calling
  `connection_aborted()`, and `Assistant::stream()` takes that
  connection as its second argument.

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
- The assistant proposing changes, behind the `assistantWrites` setting
  and `AiAccessInterface::canUse($user, Feature::AssistantWrites)`
  (`capabilities.ai.assistantWrites`): Vector's four writes reach the
  model as `Assistant\ProposingTool`s, and not at all otherwise. A call
  writes nothing — it counts against `maxToolCallsPerRun`, has its
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
