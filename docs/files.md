# Files and images

`File` and `Image` fields hold the storage key of an uploaded file. They
need `kinetis/storage` with a filesystem configured; without one the
form edits the key as plain text.

```php
Image::make('photo')->maxBytes(2_000_000),                    // png, jpeg, webp, gif by default; 5 MB
File::make('contract')->accept(['application/pdf' => 'pdf']), // pdf, png, jpeg, txt, csv by default; 10 MB
```

## Uploading

The form uploads a picked file at once, to
`POST /vector/r/{resource}/fields/{field}/upload` (multipart `file`,
and `record`, the id of the record being edited). Uploading needs
permission to write a record the file can end up on:

- the field is on the create form and the policy allows `create()`; or
- the field is on the update form and the policy allows `update()` on
  the record named by `record`.

A rich-text field's images upload through its `imageField()`, so a
rich-text field on a form counts for its image field, which need not be
on a form itself. An upload without `record` is allowed by create
permission only. Otherwise the answer is `403`; listing the resource is
not enough. The server:

- detects the format from the file's bytes — never from its name or the
  type the browser sent — and refuses one the field does not `accept()`;
- refuses a file above the field's `maxBytes()`;
- stores it privately under a key it generates,
  `vector/{resource}/{field}/{random}.{ext}`, and answers that key with
  the client's filename as data.

The record then saves that key. A written value must be such a key,
issued for that field and stored, or the value already on the record: a
key issued for another field, or one never issued, is refused, so a
value cannot be pointed at someone else's file.

## Serving

`GET /vector/r/{resource}/{id}/files/{field}` serves a stored file only
to users who may view the record and the field: images inline, anything
else as a download, with `nosniff` and a sandboxing policy. `Image`
accepts raster formats only; SVG can carry script.

The stored key is checked again before it is read, whoever wrote it: a
key with an empty, `.` or `..` segment, a leading `/`, a backslash or a
control character, or one under `vector/` but outside that field's own
`vector/{resource}/{field}/`, answers `404`. A key outside `vector/` —
one your application stored, or one typed while uploads were
unavailable — is still served.

## Cleaning up unused files

Vector never deletes a stored file when a value changes or a record is
deleted, so an undo or a revert can still bring it back. Uploads that no
record uses — never saved, replaced by a later upload, or left by a
deleted record — stay until you prune them:

```console
vendor/bin/kinetis vector:files:prune --dry-run   # lists what would be deleted
vendor/bin/kinetis vector:files:prune             # deletes it
```

The command deletes a file under a declared file or image field's
`vector/{resource}/{field}/` once it is older than the grace period,
`--grace-hours=<n>` (24 by default, by the file's last-modified time),
so an upload in a form not saved yet survives. A file is kept while:

- a file or image column of any row holds its key, trashed rows
  included;
- a rich-text document whose `imageField()` is that field shows it;
- the resource's audit trail holds it, since a revert writes an old
  value back. A file stays as long as its audit entries do.

Keys outside `vector/`, folders of fields the registry no longer
declares, and files whose names Vector did not generate are never
touched. Run it from a scheduler, for example nightly with cron:

```console
30 3 * * * cd /srv/app && vendor/bin/kinetis vector:files:prune >> var/log/vector-prune.log 2>&1
```

A form left open longer than the grace period can lose its upload:
saving it then answers that the file is not one uploaded for the field,
and the user uploads it again.

## Good to know

- Keep the storage root private, outside the web root (`public/`); see
  the [security checklist](deployment.md#security-checklist).
- The largest upload is also bounded by the framework's `MAX_BODY_SIZE`
  and your web server's body limit.
- Without a filesystem, the descriptor reports `capabilities.uploads: false`.
- `VECTOR_STORAGE_CONNECTION` stores Vector's uploads on a named storage
  connection (`FILESYSTEM_<NAME>_*`) instead of the application's
  `FilesystemOperator` (`FILESYSTEM_DRIVER`).

## See also

- {doc}`fields` — the other field types.
- {doc}`configuration` — storage keys.
