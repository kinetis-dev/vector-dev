# Operations

What every AI call records and how it fails, then pruning, safeguards
and testing.

(ai-usage)=
## Usage and failures

Every call is a row of `vector_ai_usage`: the actor and impersonator,
the feature, the provider and model, the tokens in and out, the
duration, the tool calls and the outcome, `failed:<category>` for a
failed call ({ref}`ai-failures`).

| Calls | Feature |
|---|---|
| Each call of a plain-language filter, the repair turn included | `filters` |
| Each provider call of an assistant run, with the tool calls it asked for | `assistant` |
| Each approval: no tokens, and the outcome `approval:executed` or `approval:failed` | `assistant-writes` |
| Each writing help call, and each call of a bulk run | `writing` |

The daily budget is summed per actor since midnight UTC. Once spent, the
feature endpoints answer `429` with `Retry-After` counting the seconds
to midnight UTC, and a bulk run stops sending records; a budget of 0
turns AI off (`403`). The numbers are each call's own, never a worker's
counter: the tokens its answer reported or, for an assistant call that
ended without them, an estimate.

(ai-limits)=
### Limits and timeouts

Every provider call has one deadline, `VECTOR_AI_TIMEOUT`, over the
send, the first byte and the last, a stream's included; the byte ceiling
`VECTOR_AI_MAX_RESPONSE_BYTES`; and at most
`VECTOR_AI_MAX_OUTPUT_TOKENS` tokens of answer. A plain-language filter,
asked for while someone waits, gets 30 seconds, or `VECTOR_AI_TIMEOUT`
when shorter, and is not retried. A Bedrock call keeps its signing
client's bound.

### Assistant call billing

The charge depends on whether the provider produced output, not on an
HTTP status. Providers report most tokens at the end of a stream, so a
stopped or failed one is estimated: a token per 4 bytes of the
instructions, the conversation as sent, each call's newest message and
the streamed text (tool definitions are sent but not counted). Each side
is the larger of reported and estimated, never their sum, and an
estimated outcome ends in `:estimated`. Each call is one row.

| The call ended with | Input | Output | Outcome |
| --- | --- | --- | --- |
| a complete answer | reported | reported | `answered` |
| an answer stopped because the browser left | reported, or estimated when none was | the larger of reported and streamed | `client_gone`, `:estimated` when either side was |
| a stream stopped because the browser left, before any text | estimated | streamed | `client_gone:estimated` |
| a failure after the provider streamed anything | estimated | streamed | `failed:<category>:estimated` |
| a failure with nothing streamed (no connection, an HTTP or Bedrock refusal, a timeout before the first byte) | 0 | 0 | `failed:<category>` |
| still in flight when the run was abandoned | as the two rows above, by whether anything streamed | | |

A failure that produced nothing costs nothing, however often retried:
the budget is an abuse limit, not a bill.

(ai-departures)=
### Browser departures

When the browser leaves, `StoppableHttpClient` stops the provider stream
at the next event it reads, by asking the request's
`Kinetis\Runtime\ClientConnectionInterface`. Under FrankenPHP's worker
mode the run ends with the text so far; behind PHP-FPM, nginx closing
the connection ends the script at its next write, possibly before the
call is recorded, so those tokens count against no budget (watch the
provider's own usage report); a Bedrock call cannot be stopped, and runs
to its deadline.

A run counts as ended by the browser only when the application stopped
it: its stream asked whether to stop and was told yes, or the assistant
saw the connection gone between two steps (before the first, nothing is
sent or charged). It is logged at info, `An assistant run ended after
the browser left.`, with the outcome `client_gone` and no provider
warning. Anything else (a
provider's refusal, a cut stream, a tool's exception, a runaway model, a
database error) is a failure, logged as a warning for a provider's and
an error for the run's own, whether or not the browser is still there;
its absence decides only whether more is written to it.

(ai-failures)=
### Provider failures

A failed call is reported by category, `timeout`, `too_large`,
`refused`, `unavailable` or `invalid_request`, with a status from `502`
to `504` and a message quoting neither the prompt, the answer nor a key.
It is logged with the category and the provider's status only, since an
error body can quote the prompt and the request carries the key.

(ai-status)=
### Status codes

| Status | When |
|---|---|
| `403` | The feature is off or unconfigured, the daily budget is 0, or `canUse()` refuses; for a filter, also a resource the user may not list or that is kept from the model; for writing help, a field its checks refuse ({ref}`ai-writing-checks`). |
| `404` | No such resource; for writing help, also a field the user does not see or a record they cannot read. |
| `413` | Writing help: a body over 256 KiB. |
| `422` | The body breaks a named rule; for a filter, the answer is invalid after one repair (a lookup on a relation the user may not list counts), a lookup matches nothing, or an ambiguous lookup is inside an "any of" group. |
| `429` | Today's token budget is spent ({ref}`ai-usage`). The assistant answers it before any frame. |
| `502` to `504` | The provider failed ({ref}`ai-failures`); a begun assistant stream ends with an error frame instead ({ref}`ai-assistant-stream`). |

The approval ({ref}`ai-approval-endpoints`) and key ({ref}`ai-keys`)
endpoints answer their own.

(ai-logging)=
### Logging

Nothing a model is sent or answers is ever logged: no sentence, text,
guidance, prompt, message, tool argument or answer, model output,
provider error body, header or key.

Each assistant run is one log line: the actor and impersonator, the
provider, model, tokens and duration, the tools called and their count,
and how it ended, with the failure's category and status. A failed run
is a **warning** when the provider failed, and an **error** when the run
did: category `error` for a bug, `runaway_tools` for a model calling
past its budget. The line carries the exception's class, file and line,
never its message, which can quote data; only under
`APP_ENV=development` are the message (`exception_message`) and the
exception attached.

Each approval decision is one log line: the actor, impersonator,
approval id, tool and outcome, never the arguments, the result or an
exception's message.

(ai-prune)=
## The prune command

Run this from a scheduler, hourly for example:

```console
vendor/bin/kinetis vector-ai:approvals:prune            # --days=30
```

It marks pending proposals past their expiry `expired`; marks `failed`,
with the unknown-outcome error ({ref}`ai-approvals`), every proposal
still `executing` ten minutes (`ApprovalStore::EXECUTING_MINUTES`) after
approval, never running it; deletes proposals decided (executed,
rejected, expired or failed) more than `--days` days ago; and deletes
the rewrite rows of ended bulk runs (below). The demo's `scheduler`
service runs it hourly after `vector:jobs:reap` ({doc}`/demo`). The
approval endpoints apply expiry and the stale-executing rule themselves,
whether or not it has run.

(ai-bulk-retention)=
### Bulk rewrite retention

`vector_ai_rewrites` keeps a run's texts until it has ended. `finish()`
builds the CSV and deletes nothing, because Vector saves the file only
after `finish()` returns, so a failed save loses no proposal. The sweep
deletes the rows of every job no longer queued or running: done (marked
in the write that saves the file), failed, or marked failed as stalled
by `vector:jobs:reap`. A job whose worker died stays `running` until the
reap marks it, so schedule **`vector:jobs:reap` before
`vector-ai:approvals:prune`**, or a dead run's rows are never deleted.

Until then the texts are kept twice, in the rows and in the CSV on the
job row. Vector does not re-run a failed job, so the rows let an
operator recover its proposals by hand.

## Safeguards

- **It can only propose.** Only the person's approval runs a write, and
  it runs the stored arguments.
- **It cannot approve.** The ids are the server's, bound to the person
  and their impersonator, behind the admin's authentication and CSRF
  protection.
- **It cannot reach past the person.** Each write runs as them, under
  every Vector policy: a record's text telling the model to *delete all
  orders* can at worst produce a proposal for them to reject.
- **It holds no state between requests.** The row is the proposal, the
  stream's agent ends with the request, and an approval is a fresh
  request.

(ai-testing)=
## Testing

The `fake` provider type replays scripted replies, for a chat, a stream
or a structured call alike:

```ini
VECTOR_AI_PROVIDERS=fake
VECTOR_AI_FAKE_TYPE=fake
```

Bind the replies on AppScope in a test. Each is keyed by the last text
block of the last user message (a filter's sentence, or its repair
turn's text); `ScriptedReplies::of()` keys by its SHA-256. A
`ScriptedCalls` records every request the providers were sent,
structured schema included:

```php
use Kinetis\VectorAi\Testing\ScriptedCalls;
use Kinetis\VectorAi\Testing\ScriptedReplies;

$calls = new ScriptedCalls();
$app->instance(ScriptedReplies::class, ScriptedReplies::of([
    'products low on stock' => '{"conditions":[{"field":"stock","op":"lte","value":"5"}],"anyOf":[],"q":null,"explanation":"Stock of 5 or less."}',
], $calls));
```

A reply can instead be a run's turns, each asking for `toolCalls` or
answering with `text`, taken once the previous turn's tool results are
in. The scripted provider streams text too, and keeps every request,
tool results included:

```php
$app->instance(ScriptedReplies::class, ScriptedReplies::of([
    'Which products are low on stock?' => [
        ['toolCalls' => [['name' => 'vector_query', 'arguments' => ['resource' => 'products', 'filter' => ['stock' => ['lte' => '10']]]]]],
        ['text' => 'Lowest first: products#3.'],
    ],
], $calls));
```

A run ending in a proposal has a write tool among its `toolCalls`. The
turn telling the model how the person decided ends with
`Assistant::OUTCOME_PROMPT`, which a reply can be keyed by.
