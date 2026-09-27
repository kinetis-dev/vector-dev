# Authorization

Once a user is admitted ({doc}`authentication`), a resource's **policy**
decides what they may do with it, and its `scope()` decides which
records they see. Both are enforced by the API; the UI only reflects the
result.

## Policies

`policy()` names a class implementing `ResourcePolicyInterface`,
evaluated through `kinetis/authorization`'s `Gate`. Extend
`ResourcePolicy` and override only what you restrict:

```php
final class OrderPolicy extends ResourcePolicy
{
    public function update(CurrentUserInterface $user, array $record): bool
    {
        return $record['status'] !== 'shipped';
    }

    public function delete(CurrentUserInterface $user, array $record): bool|AuthorizationResponse
    {
        return AuthorizationResponse::deny('Orders are cancelled, not deleted.');
    }
}
```

A denial with a message shows that message to the user.

| Ability | Decides |
|---|---|
| `viewAny`, `view` | Listing the resource, and seeing one record. |
| `create`, `update`, `delete` | Writing. `$record` is the serialized row. |
| `restore`, `forceDelete` | The trash. |
| `export` | CSV export. |
| `runAction` | Running an action, alongside the action's own `canRun()`. |
| `viewField` | Seeing a field at all. |
| `attach`, `detach` | Adding and removing one record in a many-to-many; both follow `update` unless overridden. |
| `setDefaultView` | Making a shared view everyone's default. |

A resource without a policy allows everything to every admitted user.

## Row scope

`scope()` on the resource narrows the query for a user — a sales rep
sees their own customers. It applies to every read: lists, counts,
summaries, exports, badges and search.

```php
public function scope(Query $query, CurrentUserInterface $user): Query
{
    return $user instanceof SalesRep ? $query->where('owner_id', '=', $user->id()) : $query;
}
```

## Field visibility

`viewField($user, $field)` hides a field from a user completely: it
leaves their descriptor, every record, the audit trail, exports and MCP
results, and a filter, sort, column or written value naming it is
refused as unknown.

```php
public function viewField(CurrentUserInterface $user, string $field): bool
{
    return $field !== 'phone' || $user instanceof SupportLead;
}
```

The primary key cannot be hidden; a hidden title field falls back to
the key. Hiding a field that a new record requires makes creating
impossible for that user, and the descriptor says so.

## Per-record permissions

Every record the API returns carries what this user may do with it —
`update`, `delete`, `restore`, `forceDelete` and the `actions` they may
run — and the UI shows exactly those buttons.

## See also

- {doc}`authentication` — who is admitted.
- {doc}`audit` — what is recorded.
