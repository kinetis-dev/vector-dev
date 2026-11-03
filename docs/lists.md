# Lists

A resource's list is where most work starts. Search, sort, filters, grouping
and the page are one request to the server, so it works the same on ten
records or ten million.

## Search, sort and page

- **Search** matches the resource's searchable fields, ignoring case,
  as you type: text that contains the term, the title of a searchable
  relation (products by their category's name), and whole words in the
  fields the resource indexes for full-text search.
- Click a **column header** to sort by it; click again to reverse.
- The footer pages through the list and sets how many rows a page shows.
- Click a **row** to open the record.

```{image} _static/screenshots/products-list-light.png
:alt: The products list: search, views, grouping and trash selectors, the published summary card, and a page of products with status, category, price and stock
:class: only-light
```

```{image} _static/screenshots/products-list-dark.png
:alt: The products list: search, views, grouping and trash selectors, the published summary card, and a page of products with status, category, price and stock
:class: only-dark
```

## Paging

A list pages in one of three ways, set by its resource (see
{doc}`resources`):

- **By page number, counted** — the default. The footer shows the
  range and the total (*26–50 of 400*), and pages to any page, the last
  included. Each page costs a COUNT of the whole match.
- **By page number, uncounted** — a resource that declares
  `withoutCount()`. The footer shows the page it is on (*Page 2*) with
  **Previous** and **Next**, and no total and no jump to the last page:
  the server reads one row past the page to know whether another
  follows, instead of counting the match. Sort, grouping and the
  totals row work as on a counted list.
- **By cursor** — a resource that declares `cursorPaging()`. The footer
  has **Previous** and **Next** only: no total and no page number. The
  list is ordered by the identifier, so it cannot be sorted or grouped,
  and it shows no totals row. Search and filters still apply.

Changing the search, a filter or the page size goes back to the first
page. The list's position is not part of the URL: reloading a cursor
list returns it to its first page.

## Filters

**Add filter** picks a field, a condition and a value. The conditions
offered fit the field: *contains* for text, *<* and *is between* for
numbers and dates, *is any of* for choices, *is empty* for optional
fields. Every filter shows as a chip; remove a chip to drop it.

All filters must hold. For alternatives, **Add "any of"** starts a group
where any one alternative may hold — *status is archived*, **or**
*stock ≤ 5*. A list can hold several such groups.

```{image} _static/screenshots/products-filters-light.png
:alt: The products list filtered to Stock < 20, the filter shown as a chip above the rows
:class: only-light
```

```{image} _static/screenshots/products-filters-dark.png
:alt: The products list filtered to Stock < 20, the filter shown as a chip above the rows
:class: only-dark
```

On a resource with a trash, the trash selector shows *Active* records,
the *Trash*, or both.

## Group and summarize

**Group by** gathers the rows under a header per value, with the number
of rows; expand or collapse each group. A resource that summarizes
columns shows a totals row — a sum, an average, a minimum, a maximum or
a count — over every record that matches, not just the page.

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

```{image} _static/screenshots/products-saved-view-light.png
:alt: The products list showing the saved view Low stock: its name on the Views button, its filters as chips, the seven matching products, and the Views menu open with All records, the personal view Drafts and the shared view Low stock selected
:class: only-light
```

```{image} _static/screenshots/products-saved-view-dark.png
:alt: The products list showing the saved view Low stock: its name on the Views button, its filters as chips, the seven matching products, and the Views menu open with All records, the personal view Drafts and the shared view Low stock selected
:class: only-dark
```

## Select and act

Tick rows to select them. The selection bar then offers:

- **Select all *n* matching** — extend the selection from the checked
  rows to every record the list matches, across pages. A list that is
  not counted offers **Select all matching records**, without a number,
  and its confirmations name every matching record rather than a count.
- **Edit** — write the same values to every selected record. Pick the
  fields to change; everything else is left as it is.
- **Delete** — on a resource with a trash, moves them there; in the
  trash, **Restore** and **Delete forever**.
- The resource's **actions**, such as *Publish* or *Adjust stock*.

Every selected record is checked against your permissions. If one of
them may not be changed, the whole operation is refused and nothing
changes.

```{image} _static/screenshots/products-bulk-light.png
:alt: Three products checked: the selection bar offers Select all 150 matching, Edit, Delete and the resource's actions — Publish, Archive, Adjust stock, Reprice
:class: only-light
```

```{image} _static/screenshots/products-bulk-dark.png
:alt: Three products checked: the selection bar offers Select all 150 matching, Edit, Delete and the resource's actions — Publish, Archive, Adjust stock, Reprice
:class: only-dark
```

## Edit in place

A column with a pencil on hover can be edited in its cell. Changes
across rows are collected — changed cells stay highlighted — until you
**Save changes** (all at once, in one transaction) or **Discard**. If
someone else changed one of those records since the list loaded, nothing
is saved and you are asked to reload. Leaving the list with unsaved
edits asks first.

## Export and import

- **Export** downloads the list as it is filtered, sorted and columned,
  as CSV or — where the server offers it — as an Excel workbook (XLSX)
  whose numbers, amounts, yes/no values and dates are real spreadsheet
  values. A large CSV export, and every Excel export, runs as a
  background job; you are notified when the file is ready.
- **Import CSV** reads a file, suggests which column goes to which
  field, and lets you correct the mapping or skip a column. Choose
  whether rows **create new records**, **update existing records**, or
  **update or create**; the last two match each row by a key column —
  the ID or a unique field such as a SKU. Re-importing a corrected
  export this way changes records instead of duplicating them. The
  import runs as a background job; rows that fail come back as a file
  to fix and import again.

## See also

- {doc}`records` — one record at a time.
- {doc}`using-the-admin` — jobs and notifications.
