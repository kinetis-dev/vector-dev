# Lists

A resource's list is where most work starts. Everything on it — search,
sort, filters, grouping, the page — is one request to the server, so it
works the same on ten records or ten million.

## Search, sort and page

- **Search** matches the resource's searchable fields, ignoring case,
  as you type.
- Click a **column header** to sort by it; click again to reverse.
- The footer pages through the list and sets how many rows a page shows.
- Click a **row** to open the record.

## Filters

**Add filter** picks a field, a condition and a value. The conditions
offered fit the field: *contains* for text, *<* and *is between* for
numbers and dates, *is any of* for choices, *is empty* for optional
fields. Every filter shows as a chip; remove a chip to drop it.

All filters must hold. For alternatives, **Add "any of"** starts a group
where any one alternative may hold — *status is archived*, **or**
*stock ≤ 5*. A list can hold several such groups.

On a resource with a trash, the trash selector shows *Active* records,
the *Trash*, or both.

## Group and summarize

**Group by** gathers the rows under a header per value, with the number
of rows; expand or collapse each group. A resource that summarizes columns shows a
totals row — a sum, an average, a minimum, a maximum or a count — over every
record that matches, not just the page.

## Columns

**Columns** shows, hides and reorders the list's columns, and **Reset**
restores the default. Your choice is remembered for your account.

## Saved views

**Views** stores the list as it is — filters, search, sort, grouping,
columns, page size — under a name.

- **Save current as view…** creates one; **Share with every admin**
  makes it visible to everyone.
- **Open this resource with this view** makes it your default.
- A shared view can also become **Default for everyone**: the list opens
  with it for anyone without a default of their own, if your account is
  allowed to set it.
- **Update view with the current list** and **Delete view** change a
  view you own.

## Select and act

Tick rows to select them. The selection bar then offers:

- **Select all *n* matching** — extend the selection from the checked
  rows to every record the list matches, across pages.
- **Edit** — write the same values to every selected record. Pick the
  fields to change; everything else is left as it is.
- **Delete** — on a resource with a trash, moves them there; in the
  trash, **Restore** and **Delete forever**.
- The resource's **actions**, such as *Publish* or *Adjust stock*.

Every selected record is checked against your permissions. If one of
them may not be changed, the whole operation is refused and nothing
changes.

## Edit in place

A column with a pencil on hover can be edited in its cell. Changes
across rows are collected — changed cells stay highlighted — until you
**Save changes** (all at once, in one transaction) or **Discard**. If
someone else changed one of those records since the list loaded, nothing
is saved and you are asked to reload. Leaving the list with unsaved
edits asks first.

## Export and import

- **Export CSV** downloads the list as it is filtered, sorted and
  columned. A large export runs as a background job; you are notified
  when the file is ready.
- **Import CSV** reads a file, suggests which column goes to which
  field, and lets you correct the mapping or skip a column. The import
  runs as a background job; rows that fail come back as a file to fix
  and import again.

## See also

- {doc}`records` — one record at a time.
- {doc}`using-the-admin` — jobs and notifications.
