# Audit trail and impersonation

## The audit trail

Every write through Vector (create, update, delete, restore, attach,
detach, actions, bulk and inline edits, imports, repeater rows) is
recorded in `vector_audit` with the actor, and the impersonator when
there is one. Creates, updates, reverts and membership changes carry the
field-level changes the writer observed, each value as the record
serialized it, so `GET /vector/r/{resource}/{id}/audit` answers a JSON
object as an object; deletes, restores and action runs are recorded as
the event alone (`action:<name>` for an action). A
record's **History** shows it, limited to the fields the viewer may see.
The changes are stored as JSON on Vector's own connection, so a `Json`
field's value nests only as deep as that connection stores, less the
two levels an entry wraps around it ({doc}`fields`, "How deep a value
nests").

```{image} _static/screenshots/product-history-light.png
:alt: A product's History: an Adjust stock action run, then an update publishing it (Status draft → active, Released at set), then an update of its Price, each by user 3 with its time, and Revert on both updates
:class: only-light
```

```{image} _static/screenshots/product-history-dark.png
:alt: A product's History: an Adjust stock action run, then an update publishing it (Status draft → active, Released at set), then an update of its Price, each by user 3 with its time, and Revert on both updates
:class: only-dark
```

Action runs are also logged, once per run, in `vector_action_events`.

A write that came through another channel than the admin's own hand
carries its **origin**: the AI assistant's approved proposals are
recorded with `origin` `assistant` ({doc}`ai/assistant`), and History shows them
*via assistant*. Code that writes on another channel's behalf registers
`new ActorContext($user, $impersonatorId, origin: 'my-channel')` (a
lowercase token of at most 40 characters) on the request scope before
anything that writes is resolved. `origin` is null for every other
write, and `GET /vector/r/{resource}/{id}/audit` returns it on each
entry. The column comes with the `vector_write_origin` migration
`vector:install` publishes; a write without an origin is recorded
whether or not it has been applied.

## Revert

`POST /vector/r/{resource}/{id}/audit/{entry}/revert` puts back what one
`update` (or earlier `revert`) changed:

- each field of the entry the user may see and edit gets its earlier
  value, as recorded: a JSON object, `{}` and a nested `{}` included,
  is put back as an object where the field keeps objects
  ({doc}`fields`);
- the write goes through the update path: policy, validation,
  conditions, dependent choices and hooks all apply;
- it is pinned to the record's current version, and recorded as a
  `revert`.

If any of those fields changed since the entry, the revert answers `409`
and writes nothing, so it never overwrites a later edit. Collections and
read-only fields are left alone. Other events (`create`, `delete`,
`attach`, actions) cannot be reverted and are refused with
`not_revertable`.

## Impersonation

An admin can act as another user to see what they see. Vector owns the
endpoints, the permission check and the logging; your application owns
the credential, through `ImpersonationInterface`:

| Method | Does |
|---|---|
| `canImpersonate($user)` | Whether this user may impersonate at all. |
| `start($actor, $targetId)` | Switches the credential: a new `accessToken` in bearer mode, a swapped session and `reload: true` in cookie mode. |
| `stop($impersonated)` | Restores the impersonator's own credential. |
| `describe($targetId)` | The target's id and name, or null when they do not exist. |

Register it on the request scope, typically in your authentication
middleware, which knows the mode. The user object reports who is
impersonating it through `ImpersonatedUserInterface`, and every audit
and log row records both.

The UI never renews an impersonation token, so its lifetime is yours to
bound. The demo's lasts no longer than the token that started it; in
cookie mode, impersonation lasts as long as the session (see the
[known limits](deployment.md#known-limits)).

In the UI, set `usersResource` in `app.config` to the resource whose
records are users; its record pages then offer **Impersonate**.

## See also

- {doc}`records`: history and revert from a user's side.
- {doc}`authentication`: the two credential modes.
