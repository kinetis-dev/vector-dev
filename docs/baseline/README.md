# Docs URL baseline

These files freeze what the Sphinx build publishes under `/vector/docs/`.
Search engines and other sites, the Kinetis documentation among them, link
to it, so a theme or build change must not drop any of it:

- `urls.txt`: every built page, plus `sitemap.xml`, `objects.inv` and
  `searchindex.js`.
- `anchors.json`: the ids inside each page's main landmark, the outermost
  `<main>` or `role="main"` element: pydata's `<main>`, or Furo's
  `<article role="main">`. The landmark's own id, positional
  sphinx-design `sd-*` ids and ids inside a `data-pagefind-ignore`
  subtree are excluded.
- `meta.json`: each page's `<title>`, canonical link, `og:url`, `og:image`
  and description, plus the sitemap locs and the `sitemap_excludes` pages.
- `sources.txt`: the `docs/*.md` names.
- `aux.txt` (curated): `keep` must be present, `remove` must be absent,
  `remove-pending` may be present. Files listed here are excluded from
  `urls.txt`, `anchors.json` and `meta.json`.
- `external-anchors.txt` (curated): pages and `page#anchor` targets that
  other sites link to. Today these are the links from the Kinetis
  documentation's `docs/*.md`.
- `redirects.txt` (curated): `old-path new-url`. The old path must then be a
  stub whose meta refresh or canonical link points at the new URL. Its
  baselined anchors and head fields are no longer checked.

New pages and new anchors are always allowed.

## Commands

```sh
python3 docs/tools/check_url_baseline.py capture docs/_build/html
python3 docs/tools/check_url_baseline.py check docs/_build/html
python3 docs/tools/check_url_baseline.py check docs/_build/html --live
python3 -m unittest docs/tools/test_check_url_baseline.py
```

CI and the docs deploy run `check` after the build. `--live` compares the
production sitemap, `https://kinetis.dev/vector/docs/sitemap.xml` unless
another URL is given, with the captured locs. It needs the network and is
run by hand only.

`capture` regenerates the four derived files. It writes the three curated
files only when they are absent and never overwrites an edited copy.

## Re-capturing

Re-capture only together with a `redirects.txt` entry for every removed or
moved URL, or with an explicit, human-reviewed `aux.txt` decision. A
re-capture that only makes `check` pass removes the protection.
