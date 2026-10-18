# Changelog

All notable changes to `@kinetis/vector-ui` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
