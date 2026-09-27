# Records

## The record page

Opening a record shows its fields, then a panel per related collection,
then its **History**. The buttons at the top depend on what you may do
with this record: **Edit**, **Delete**, **Actions**, and, in the trash,
**Restore** and **Delete forever**.

## Creating and editing

**New *record*** on a list, or **Edit** on a record, opens a form with
one input per field you may write.

- **Sections and tabs** — a long form may be grouped. A folded section
  opens, and a tab switches, when a field inside it needs attention; a
  tab with a problem carries a red dot.
- **Validation** — the form checks what it can as you go (a required
  field, a length, an e-mail address). What only the server can check,
  such as a duplicate name, comes back on the field it concerns.
- **Conditional fields** — a field may appear, become required or be
  disabled depending on another; a product's *Released at* shows only
  once it is no longer a draft.
- **Dependent choices** — a choice may wait for another: pick a team
  before its agents are offered.
- **Rows** — some records own rows edited inside their form, such as an
  order's lines: add, change or remove them, and they save with the
  record.
- **Files and images** upload as soon as you pick them.

Editing sends only what you changed. If someone saved the record after
you opened it, your save is refused rather than overwriting their
change; reload and edit again.

## Related records

A collection on the record page lists its related records.

- On a many-to-many relation, **Attach** adds existing records and
  **Detach** removes one. Detaching removes only the link, never the
  record.
- On a relation the record owns, **New *record*** creates one already
  linked to it.

## History

**History** lists every change to the record: who made it, when, and
each field's value before and after. Changes made while impersonating
name both people. **Revert** on an edit puts the earlier values back,
through the same checks as an edit. If one of those fields changed
since, the revert is refused rather than overwriting the later change.
The revert itself appears in the history.

## Deleting and the trash

**Delete** asks for confirmation. On a resource with a trash, the record
moves there and can be restored. Otherwise deletion cannot be undone.
**Delete forever** removes a record from the trash for good.

## Actions

**Actions** lists what may be run on this record, such as *Publish*. An
action may ask for values first, or confirmation. It reports its result
as a notification, a file to download, a page to open, or a background
job to follow.

## See also

- {doc}`lists` — many records at once.
- {doc}`using-the-admin` — navigation, search and jobs.
