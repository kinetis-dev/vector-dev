# Actions

An action is an operation beyond create, update and delete: publish,
reprice, escalate. It is a class extending `VectorAction`, listed in its
resource's `actions()`.

```php
use Kinetis\Vector\Actions\{ActionRequest, ActionResponse, VectorAction};

final class AdjustStock extends VectorAction
{
    public function fields(): array
    {
        return [Number::make('delta', 'Change by')->required()];
    }

    public function canRun(CurrentUserInterface $user, ?array $record): bool
    {
        return $record === null || $record['status'] !== 'archived';
    }

    public function handle(ActionRequest $request): ActionResponse
    {
        foreach ($request->entities() as $product) {
            if (!$product->adjustStock($request->field('delta'))) {
                return ActionResponse::danger('Stock cannot go below zero.'); // nothing is saved
            }
        }

        return ActionResponse::message("{$request->count()} adjusted.");
    }
}
```

`fields()` become the action's dialog, validated like a form, with
conditions and defaults; an action's fields are scalar, select and
belongs-to fields. A multiple select hands the action a list of option
values, never enum cases. `canRun()` decides whether the action is offered
(`$record` is `null`) and whether it may run on a given record. The
action's URL segment is `name()`: by default the class name without
`Action`, in kebab-case (`PublishAction` is `publish`). The constructor
may inject what a controller may.

## How it runs

For every run, Vector:

1. checks the resource policy's `viewAny()` and `runAction()`, and
   `canRun()` without a record;
2. validates the dialog's values;
3. reads each selected record as the user may view it and refuses the
   run when `canRun()` refuses one;
4. loads the entities and hands them to `handle()`;
5. saves everything in one flush, unless the response is `danger`;
6. logs the run in `vector_action_events` and adds an entry naming the
   action to each record's audit trail: every record handed to
   `handle()`, or only those the response declares (below).

`affected` in the response is the count `handle()` gave it; when that
is 0 on a run with records, it reports how many records the run was
handed. An action that skips some of its records declares the ones it
acted on instead, with `->withRecords($ids)` on its response:

```php
return ActionResponse::message("{$count} published.")->withRecords($published);
```

Then only those records are logged and audited (none for an empty
list, which still flushes and logs the run) and `affected` is their
count. An identifier the action was not handed is ignored, so an
action cannot audit a record outside its selection (or its chunk, when
queued). The declaration is not part of the response the client sees.

An action runs on one record, on the selected records, or on every
record a filter matches ("all matching"), within the resource's bulk
limit.

## Shaping the action

| Method | Effect |
|---|---|
| `label()`, `icon()` | How it is named and shown. |
| `confirmation()` | A question asked before it runs. |
| `destructive()` | Shown and confirmed as dangerous. |
| `standalone()` | Runs on no records, from the list's toolbar. |
| `showOnIndex()`, `showOnDetail()` | Where it is offered. |
| `queued()`, `chunkSize()` | Runs as a background job, in chunks; see {doc}`jobs`. |
| `finish()` | A queued run's last step, once its chunks are done; see below. |

## Responses

| Response | The user sees |
|---|---|
| `ActionResponse::message()` | A confirmation. |
| `ActionResponse::danger()` | An error; nothing is saved (`422`). |
| `ActionResponse::redirect()` | The admin navigates to a page. |
| `ActionResponse::openUrl()` | A URL opens. |
| `ActionResponse::download()` | A file downloads. |
| queued | A job to follow on **Jobs**; {doc}`jobs` covers its delivery and what it must tolerate. |

A queued action's chunks each get the job they run in as
`$request->jobId` (null on the synchronous path), the same for every
chunk of one run. After the last chunk, Vector calls `finish()` once,
with no records and the same `jobId`: return
`ActionResponse::download($contents, $name, $type, $message)` to leave a
file on the job (the **Jobs** page offers it for download, as an
export's), `danger()` to fail the job, or null (the default) for
nothing. `finish()` flushes nothing. Keep what it needs from the chunks
somewhere keyed by `jobId`, since each chunk is a fresh request: every
chunk, and `finish()`, runs on a request scope of its own, as the admin
who started the job, so nothing a request-scoped service remembered in
one chunk (a cache, settings it read, which fields the admin sees) is
what the next one sees.

## See also

- {doc}`jobs`: background execution.
- {doc}`audit`: what an action leaves behind.
