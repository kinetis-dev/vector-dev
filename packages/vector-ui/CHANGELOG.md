# Changelog

All notable changes to `@kinetis/vector-ui` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
