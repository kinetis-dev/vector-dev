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
- The assistant: a sparkle button in the app bar (`defineShellAction`)
  opening an end-side drawer with the conversation, a composer, *New
  conversation* and a line naming the page the questions are about; it
  belongs to the layout, so it survives navigation. *Ask the assistant…*
  in the command palette (`definePaletteCommand`) opens it asking what
  was typed. Answers stream in from `POST /vector/ai/assistant` (the
  Vercel AI SDK data-stream protocol, parsed as it arrives), falling back
  to the whole JSON answer where the runtime answers `501`; they are
  rendered through the admin's own Markdown sanitizer, with each
  `resource#id` of a resource the descriptor knows linked to the record.
  A failure shows its reason and Retry; a spent budget says when it
  renews. The conversation is the signed-in user's: it is forgotten, and
  the drawer closed, when the user changes (an impersonation), the
  session ends or the admin signs out. Shown only while
  `capabilities.ai.assistant` is on.
