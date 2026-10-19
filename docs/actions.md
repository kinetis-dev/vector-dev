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
conditions and defaults; an action's fields are scalar, enum and
belongs-to fields. `canRun()` decides whether the action is offered
(`$record` is `null`) and whether it may run on a given record.
The action's URL segment is `name()`: by default the class name without
`Action`, in kebab-case (`PublishAction` is `publish`). The constructor may inject what a controller may.

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
   action to each record's audit trail.

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

## Responses

| Response | The user sees |
|---|---|
| `ActionResponse::message()` | A confirmation. |
| `ActionResponse::danger()` | An error; nothing is saved (`422`). |
| `ActionResponse::redirect()` | The admin navigates to a page. |
| `ActionResponse::openUrl()` | A URL opens. |
| `ActionResponse::download()` | A file downloads. |
| queued | A job to follow on **Jobs**. |

A queued action runs in chunks; {doc}`jobs` covers how it is delivered
and what it must tolerate.

## See also

- {doc}`jobs` — background execution.
- {doc}`audit` — what an action leaves behind.
