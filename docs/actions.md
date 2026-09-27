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
conditions and defaults. `canRun()` decides, per record, whether the
action is offered and allowed.

## How it runs

For every run, Vector:

1. authorizes each record through the resource's policy and `canRun()`;
2. validates the dialog's values;
3. loads the entities and hands them to `handle()`;
4. saves everything in one flush, unless the response is `danger`;
5. logs the run and writes each change to the audit trail.

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

A queued action must tolerate a chunk running twice: a chunk's flush and
the progress recorded after it are two statements.

## See also

- {doc}`jobs` — background execution.
- {doc}`audit` — what an action leaves behind.
