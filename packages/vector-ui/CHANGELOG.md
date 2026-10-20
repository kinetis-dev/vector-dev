# Changelog

All notable changes to `@kinetis/vector-ui` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.10.0] - 2026-10-20

### Added

- A list whose resource is `withoutCount` pages by previous and next
  with the page number, and shows no total and no last page. "Select all
  matching" and its confirmations name every matching record without a
  count.
- A list whose resource is `cursorPaging` pages by cursor, keeping the
  cursors it visited so Previous works. It offers no sort, grouping,
  page numbers, total or totals row; search, filters and page size
  return it to the first page, and so does a reload.
- A relation panel whose target is `withoutCount` pages by previous and
  next, and copying a record's many-to-many memberships walks such a
  target by `hasMore`.
- `useVectorResource().listByCursor()`, `useVectorCursorPager()`,
  `VxListPager` and the `VectorCursorPage` type. `VectorPage` gains an
  optional `hasMore`.

### Changed

- `VectorPage.total` and `lastPage` are now `number | null`: they are
  `null` on a page of a `withoutCount` resource. So are the `count` prop
  of `VxActionDialog` and `VxListBulkEdit` and the `total` prop of
  `VxListBulkBar`, `null` meaning every matching record of a list that
  is not counted. Code that reads these, or renders these components,
  must handle `null`.

### Fixed

- A list returns to its first page on a new search, filter, sort, page
  size or saved view only once that page has arrived. A request that
  fails leaves the page number and the rows as they were, instead of
  showing page 1 over the old page's rows; the next step asks for the
  first page again. `VectorPageStep` gains `'first'`.
- Rich text under the hosted admin's CSP reports no violation. The
  sanitizer's self-test no longer carries `onclick` and `onerror`, which
  the browser checked against `script-src-attr` even in DOMPurify's inert
  document; its attributes still have to be stripped by the allow-list.

## [0.9.4] - 2026-10-19

### Changed

- The toast type `useVectorNotifications()` queues is renamed from
  `VectorNotification` to `VectorToast`. `VectorNotification` is now only
  the inbox item from `/vector/notifications`, and Nuxt no longer warns
  about a duplicated auto-import.

## [0.9.3] - 2026-10-18

### Changed

- Published to npm through a trusted publisher rather than with an
  access token. The package itself is unchanged from 0.9.2.

## [0.9.2] - 2026-10-18

### Changed

- Nuxt 4.6.1, Vue 3.5.44, Vuetify 4.2.6, Pinia 4.1.0 with `@pinia/nuxt`
  1.0.3, vue-i18n 11.4.14 and DOMPurify 3.4.17, with their lower bounds
  raised to match. The `nuxt`, `vue` and `vuetify` peer ranges are
  unchanged.

## [0.9.1] - 2026-10-18

### Changed

- Tiptap 3.32.0 for the rich-text editor, every `@tiptap/*` package
  together, and `@tiptap/suggestion` declared directly, since
  `@tiptap/extension-mention` requires that exact version as a peer.

## [0.9.0] - 2026-10-18

### Added

- A Nuxt 4 layer on Vuetify 4 that renders the `kinetis/vector`
  descriptor: navigation with badges, lists, record pages, generated
  forms, relation panels, actions, dashboards, jobs, notifications,
  settings and global search.
- Standalone mode with a bearer token and refresh, and hosted mode on
  the application's session cookie with CSRF, with an idle sign-out in
  both.
- A rich-text editor on Tiptap and a Markdown editor, rendered through
  DOMPurify.
- Overrides for cells, inputs and widget renderers in `app.config`, and
  runtime extensions through `window.Vector`, custom pages included.
- English and sixteen machine-translated languages, right to left for
  Hebrew and Arabic, with per-locale message overrides.
