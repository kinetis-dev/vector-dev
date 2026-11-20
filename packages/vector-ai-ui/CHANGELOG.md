# Changelog

All notable changes to `@kinetis/vector-ai-ui` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-19

### Added

- The layer, over `@kinetis/vector-ui` 0.13.0: the plain-language
  filter in every list's toolbar (`defineListToolbarItem`), shown when
  `capabilities.ai.filters` is on and the resource is not kept from AI —
  the answer previews as the FilterBar's own chips with its explanation,
  an ambiguous relation offers its candidates, and Apply or Discard
  decide; and the provider-key panel under the `ai` settings group
  (`GET`, `PUT` and `DELETE /vector/ai/credentials`), rendered only when
  the descriptor carries `capabilities.ai`. Delivered through Nuxt
  `extends` (standalone); hosted (no-build) delivery comes in a later
  release.
