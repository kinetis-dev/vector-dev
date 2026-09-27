# Files and images

`File` and `Image` fields hold the storage key of an uploaded file. They
need `kinetis/storage` with a filesystem configured; without one the
form edits the key as plain text.

```php
Image::make('photo')->maxBytes(2_000_000),                    // png, jpeg, webp, gif by default; 5 MB
File::make('contract')->accept(['application/pdf' => 'pdf']), // pdf, png, jpeg, txt, csv by default; 10 MB
```

## Uploading

The form uploads a picked file at once. The server:

- detects the format from the file's bytes — never from its name or the
  type the browser sent — and refuses one the field does not `accept()`;
- refuses a file above the field's `maxBytes()`;
- stores it privately under a key it generates, and answers that key.

The record then saves that key. A key issued for another field, or one
never issued, is refused, so a value cannot be pointed at someone else's
file.

## Serving

A stored file is served only to users who may view the record and the
field: images inline, anything else as a download, with `nosniff` and a
sandboxing policy. `Image` accepts raster formats only; SVG can carry
script.

## Good to know

- Vector never deletes a stored file when a value changes.
- The largest upload is also bounded by the framework's `MAX_BODY_SIZE`
  and your web server's body limit.
- `VECTOR_STORAGE_CONNECTION` stores Vector's uploads on a named storage
  connection instead of the application's default filesystem.

## See also

- {doc}`fields` — the other field types.
- {doc}`configuration` — storage keys.
