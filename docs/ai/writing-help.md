(ai-writing)=
# Writing help

Writing help rewrites, shortens, corrects or translates a field's text
into the input. The person reviews it and saves the form as their own
edit; the server never writes it.

## The writing menu

The *Writing help* menu opens from an icon at the end of a `string` or
`text` input (the right in a left-to-right language, the left in a
right-to-left one) on a create or edit page only, not in a list's bulk
edit, an action's dialog or a settings form. Rich-text and Markdown
editors have it in their toolbar. It offers **Rewrite**, **Shorten**,
**Fix grammar** and **Translate to…** (any language the UI ships, and
any other the descriptor lets the user choose).

The answer opens in a popover under the field as *Before* and *After*.
**Replace** puts it in the input; **Discard**, Escape or a click outside
drops it. A failure says why there and leaves the text as it was, and an
answer cut at the field's cap says so ({ref}`ai-writing-request`).

```{image} ../_static/screenshots/ai-writing-translate-light.png
:alt: Writing help's popover under an order's Notes field: the English note before, its French translation after, with Discard and Replace
:class: only-light
```

```{image} ../_static/screenshots/ai-writing-translate-dark.png
:alt: Writing help's popover under an order's Notes field: the English note before, its French translation after, with Discard and Replace
:class: only-dark
```

In an editor, the menu acts on the selection, or on the whole document
while nothing is selected, and is disabled only while the editor is
empty. It sends a rich-text selection's plain text, or a Markdown
field's source. Replace inserts the answer as text (a paragraph per
line; Markdown through the editor's own reader), so the document keeps
to its schema. A whole document whose text changed in any way since it
was sent is left as it is, and the admin says so. A Markdown answer is
shown only through the admin's own Markdown sanitizer.

## Eligible fields

Writing help works on `string`, `text`, `markdown` and `richtext`
fields, except a `string` field marked `->unique()`, which is an
identifier (a SKU, a code). A field's `ai.writing` meta overrides that
either way:

```php
Text::make('sku')->unique(),                              // no writing help
Text::make('code')->unique()->meta('ai.writing', true),   // writing help after all
Textarea::make('internalRef')->meta('ai.writing', false), // none, though it is text
```

`ai.writing` never widens the types, and is independent of `ai.send`
({ref}`ai-boundary`): it neither keeps a field from the model nor sends
one `ai.send` keeps back. Vector never reads the key
(`Writing\WritingGuard::META_KEY`); `WritingGuard::helps()` decides it.

(ai-bulk)=
## Bulk rewrite proposals

`Kinetis\VectorAi\Actions\ProposeRewrites` is a queued action
({doc}`/actions`, {doc}`/jobs`) that runs writing help on one field across
the selected records, **one single-shot call per record** so no record
can steer another's rewrite, and leaves its job **one CSV of
proposals**. Nothing changes until the admin imports that CSV in
**update** mode, keyed by the record's ID: each change is then an
ordinary import row of theirs, validated, authorized by the `update`
policy, hooked and audited as their own update, with no `origin`.

### Resource setup

Nothing registers the action. A resource lists a subclass naming the
field and the operation in its `actions()`:

```php
use Kinetis\VectorAi\Actions\ProposeRewrites;
use Kinetis\VectorAi\Writing\WritingOperation;

final class TidyOrderNotes extends ProposeRewrites
{
    public function field(): string { return 'notes'; }
    public function operation(): WritingOperation { return WritingOperation::Grammar; }
}

// in OrderResource
public function actions(): array
{
    return [TidyOrderNotes::class];
}
```

- The operations are `Rewrite`, `Shorten`, `Grammar` and `Translate`,
  which needs `targetLocale()` (`de`, `pt-BR`).
- `instructions()` adds guidance on style, at most 300 characters, in
  each record's user turn, never the system prompt.
- It runs in chunks of `chunkSize()` (100), offered on the list, not on
  a record's page.

Prefer `string`, `text` and `markdown` fields: a `richtext` value (HTML,
or the editor's JSON document) is sent as plain text, and a reshaped one
can fail the import's validation. The run is held to writing help's
rules and stops at the day's token budget or a full job file
({ref}`ai-bulk-run`).

(ai-bulk-csv)=
### The CSV

The **Jobs** page offers the CSV for download once the job is done
(`GET /vector/jobs/{id}/download`), named
`<resource>-<field>-proposals-<job>.csv`. Its header is the ID field and
the field (`id,notes`, say), with one row per proposal:

```text
id,notes
12,"Leave the parcel with the neighbour at no. 12, if nobody is home."
```

It is written as Vector's importer reads it (UTF-8 with a byte-order
mark, CRLF line ends), so commas, quotes, line breaks and backslashes
inside a value come back exactly. A value that **ends** in a backslash
cannot (the importer reads its closing quote as escaped), so such a
proposal or record ID is **left out** and counted as skipped: rewrite
that record by hand.

A job file holds at most 12 MiB ({doc}`/limits`). The run stops calling
the model once its rows, counted as the CSV writes them (every quote
doubled), reach 8 MiB (`ProposeRewrites::FILE_LIMIT`), and the CSV is
built in proposal order, stopping before the row that would pass
12 MiB. A proposal that did not fit is counted in the job's message
("the proposals filled the job's file") and kept in `vector_ai_rewrites`
until the sweep ({ref}`ai-bulk-retention`); run the records not sent,
or not in the file, as another selection.

The job's message counts the proposals; the records left out, and why;
the failed calls, by category, and the empty answers; the answers cut at
the cap; and the records not sent, by why (`budget`, `file_full`,
`unavailable`).

### Review and import

Delete the rows you do not want, edit any you do, and import the file
on the same resource ({doc}`/jobs`): **Update existing records**, keyed
by `id`, with both columns mapped, as the suggested mapping does. A row
whose record is gone, or that the policy refuses, lands in the
failed-rows file.

```{warning}
**Spreadsheet formulas.** A spreadsheet may evaluate a cell of this CSV
as a formula. The CSV holds the model's text exactly, and the text is
shaped by record contents. A value starting with `=`, `+`, `-` or `@` is
not guarded. Vector's exports guard such a cell with an apostrophe, but
its import does not remove the apostrophe, so here the guard would end
up in the record.

Review the CSV in a text editor, or import a spreadsheet's export of it
only after checking those cells.
```
