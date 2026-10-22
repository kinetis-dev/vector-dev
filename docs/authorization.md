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
| `restore`, `forceDelete` | The trash; both follow `delete` unless overridden. |
| `export` | Exporting, as CSV or XLSX. |
| `runAction` | Running an action, alongside the action's own `canRun()`. |
| `viewField` | Seeing a field at all. |
| `attach`, `detach` | Adding and removing one record in a many-to-many; both follow `update` unless overridden. |
| `setDefaultView` | Making a shared view everyone's default. |

A resource without a policy uses `AllowAllPolicy`: every user the
access policy admits may do everything.

## Listing and reaching

A user `viewAny()` refuses cannot reach a record by identifier either:
show, audit, files, related lists and writes all answer `403`, as the
list does. A relation value — a belongs-to choice, a many-to-many list —
must name a record the target resource's `scope()` lists for the
writer; one outside it answers `not_found`, the same as one that does
not exist.

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
results, and a filter, sort, grouping, column or written value naming
it is refused as unknown. Search does not reach it.

```php
public function viewField(CurrentUserInterface $user, string $field): bool
{
    return $field !== 'phone' || $user instanceof SupportLead;
}
```

The primary key cannot be hidden; a hidden title field falls back to
the key. Hiding a field that a new record requires makes creating
impossible for that user, and the descriptor says so.

## Membership

`attach($user, $record, $relation, $related)` and `detach(...)` decide
adding and removing one record in a many-to-many relation (both follow
`update` unless overridden). They decide the
`POST …/{id}/{relation}/attach` and `…/detach` endpoints the UI's
relation panels use, and an update that writes the whole list: every id
it adds is an attach, every id it drops a detach, and one refusal
refuses the update.

## Everyone's default view

A saved view that is shared can also be flagged `resourceDefault`. The
list then opens with it for every user who has no default view of their
own. A resource has at most one such view: flagging another view clears
the first. `setDefaultView($user)` decides who may set the flag, or save
a view that keeps it. Anyone may drop it, and the schema publishes the
answer as `permissions.setDefaultView`.

## Per-record permissions

Every record the API returns carries what this user may do with it —
`update`, `delete`, `restore`, `forceDelete` and the `actions` they may
run — and the UI shows exactly those buttons.

## See also

- {doc}`authentication` — who is admitted.
- {doc}`audit` — what is recorded.
