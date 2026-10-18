# Changelog

All notable changes to `kinetis/vector` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.9.3] - 2026-10-18

### Changed

- Released alongside `@kinetis/vector-ui` 0.9.3, whose bundled package is
  unchanged from 0.9.2; nothing in `kinetis/vector` changed.

## [0.9.2] - 2026-10-18

### Changed

- The hosted bundle is built from `@kinetis/vector-ui` 0.9.2
  (Nuxt 4.6.1, Vue 3.5.44, Vuetify 4.2.6).

## [0.9.1] - 2026-10-18

### Changed

- The hosted bundle is built from `@kinetis/vector-ui` 0.9.1
  (Tiptap 3.32.0).

## [0.9.0] - 2026-10-18

### Added

- Resources over `kinetis/orm` entities, compiled into one immutable
  descriptor at `kinetis build`: derived or declared fields, layouts,
  conditions, dependent choices, repeaters, computed and rich-text
  fields, badges and global search, and several resources over one
  entity.
- A REST admin API under `/vector`: lists with filters, "any of" groups,
  sorting, grouping, summaries and cursors; create, update, delete,
  restore, batch and inline edits under optimistic locking; uploads;
  CSV and XLSX export and CSV import; saved views, preferences,
  settings groups, dashboards and widgets, notifications and
  impersonation.
- Resource policies, row scope and field visibility, enforced on every
  request, and a field-level audit trail with revert.
- Actions, run directly or as background jobs on the application's
  queue, with large exports and imports as jobs too.
- MCP tools and the `vector://schema` resource over the same services.
- The hosted admin shell at `/vector/ui`, under a strict Content
  Security Policy, and labels translated per user.
- MySQL and PostgreSQL on every connection; Vector's own tables on a
  connection of their own.
- `vector:install`, `vector:list` and `vector:jobs:reap`, and
  `Kinetis\Vector\Testing` for an application's own test suite.
