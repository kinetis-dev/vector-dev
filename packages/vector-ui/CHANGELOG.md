# Changelog

All notable changes to `@kinetis/vector-ui` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.10.4] - 2026-10-22

### Changed

- The resource form, the resource table and the rich-text schema are
  split into smaller functions. Their behavior is unchanged.

## [0.10.3] - 2026-10-22

### Changed

- The file and image inputs, and a rich-text field's image upload, send
  the id of the record they edit in the upload's multipart `record`
  part, which kinetis/vector now requires to upload while editing
  without create permission. `useVectorResource().upload()` takes it as
  an optional argument.

### Fixed

- A 401 while impersonating in bearer mode ends the impersonation in the
  signed-in user's own session, renewed with their refresh token, and
  says so, instead of discarding that session and sending them to sign
  in; when it cannot be renewed, the login page says the impersonation
  ran out. The impersonation's expiry no longer forgets the signer's
  refresh token, and a request refused for the impersonation token is
  never repeated as the signer.
- The jobs list and the settings page say why when they fail to load,
  as the record page does, instead of showing no jobs or "no settings".
- Several requests refused (401) together, whose shared renewal of the
  session fails, sign the user out once: one trip to the login page
  instead of one per request.
- The admin refuses a descriptor whose `version` is not the contract it
  reads (4), naming both versions, instead of rendering it as if it were.
- Following a job no longer adds a listener to the page's abort signal on
  every poll without removing it; a long job's page held one per poll.
- A request refused (401) for an access token another request has
  already renewed is repeated with the new token instead of renewing the
  session a second time, which spent the new refresh token and could end
  a session that was still good.
- A relation input whose search fails says why under the input and keeps
  the choices it listed, instead of failing silently with an unhandled
  promise rejection.
- Opening a notification whose mark-as-read fails says why, instead of
  failing silently with an unhandled promise rejection.
- A problem+json error without a `detail` shows its `title` instead of
  the request's generic failure message.
- An entry of a 422 document's `errors` that is not a violation (no
  `path` list of names and indexes, or no `message` text) is ignored by
  the forms, the settings, bulk edit, action dialogs, inline list edits
  and uploads, instead of being read as one: a `null` entry made a form
  or the list's changeset fail without showing anything.
- A record form shows a server error on a field it does not show — hidden
  by `visibleWhen`, or preset by the relation the form was opened from —
  above the form, under the field's label; it was shown nowhere, and the
  save failed without a reason.

### Security

- An action answering `ActionResponse::openUrl()` opens the URL only
  when it is http(s), mailto or relative — the rule a URL cell and a
  rich-text link follow. A `javascript:` or `data:` URL, which an action
  echoing a record's value could return, is no longer opened.
- A notification's link is followed only when it is a path of the
  admin, the rule the sign-in page's `redirect` follows; `//host`,
  `/\host` and a URL with a scheme are not.
- The sign-in page's `redirect`, and a notification's link, are refused
  when they hold a backslash, whitespace or a control character anywhere:
  a browser strips tab, CR and LF from a URL, so `/<tab>/host` was
  followed as `//host`, another origin.
- A dashboard table widget's row opens its `_link` only when it is a path
  of the admin, by the same rule, and a row whose link is not is not
  offered as a link; a failed navigation is reported instead of
  rejecting unhandled.
- An action answering `ActionResponse::redirect()` is followed only when
  its URL is a path of the admin, checked before the dialog reports the
  action done; any other URL, or none, is reported and not followed.

## [0.10.2] - 2026-10-21

### Fixed

- A row of the jobs list, and a linked row of a dashboard table widget,
  takes keyboard focus and opens with Enter or Space, as a click opens
  it.
- An inline-editable list cell is a button, so Space starts editing it
  as Enter does.
- Table column headers are marked as column headers; a trend widget's
  sparkline is hidden from screen readers, which read its range and total
  beside it; the rich text editor's image picker is labelled.
- A key-value input, a repeater's rows and the filter bar's "any of"
  groups are fieldsets, with the accessible names they had.
- The Email rule, the sign-in form's e-mail check and the Regex rule's
  delimiter parsing run in linear time, accepting exactly what they
  accepted before.

## [0.10.1] - 2026-10-21

### Security

- `markdown-it` is now required at `^15.0.3`, which fixes two
  quadratic-time denials of service: a multi-line link reference
  definition (GHSA-gfr2-rv44-r23c), and inline HTML parsing with HTML
  enabled (GHSA-vfgh-jcmw-g37q).

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
