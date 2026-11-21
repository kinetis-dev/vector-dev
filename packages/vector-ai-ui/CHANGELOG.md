# Changelog

All notable changes to `@kinetis/vector-ai-ui` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- ⌘J (Ctrl+J on Windows and Linux) opens or closes the assistant's
  drawer from anywhere in the admin, its own input included, while the
  assistant is offered; a keypress something else already handled is
  left alone. Nothing on screen shows the shortcut.

### Changed

- Writing help in a rich-text or Markdown editor's toolbar works on the
  whole document while nothing is selected, and Replace puts the answer
  in place of all of it; a selection works as before. The button is
  disabled only while the editor holds no text (`ai.writing.emptyEditor`),
  and with nothing selected its title says it works on the whole text
  (`ai.writing.wholeDocument`); a document whose text changed in any way
  since it was sent is left alone (`ai.writing.changedDocument`).
- `aiOffered()`, and so `useVectorWriting().offered()`, follows
  `capabilities.ai.noWriting`: no writing help on a field the server has
  it off for — a unique string such as a SKU, or a field with the
  `ai.writing` meta false. Against a server without `noWriting`, nothing
  changes.
- One helper, `app/utils/aiAvailability.ts` (`aiCapabilities()`,
  `aiOffered()`, `aiStatusOf()`, `aiFailureMessage()`), answers whether
  the admin offers an AI feature and words a failed AI call, a spent
  budget included, for the filter, writing help and the assistant,
  instead of a copy in each. Nothing they show changes.
- The tool chips above an assistant answer show each tool once, in the
  order first shown, with how many times it answered when more than
  once (*Read with vector_query ×3*; `ai.assistant.toolTimes` and
  `ai.assistant.proposingToolTimes`), and leave out every call that was
  a tool error — a `tool-output-error` frame, or `ok: false` in the
  whole answer's `toolCalls`, now `{name, ok}` objects. A whole answer
  from a server that sends bare tool names still shows them, each taken
  as answered.

### Removed

- The `ai.writing.selectFirst` message: the editor's writing help no
  longer needs a selection.
- *Ask the assistant…* in the command palette, its `ai.assistant.ask`
  message and `useVectorAssistant().ask()`: the app-bar button and ⌘J
  open the assistant.

### Fixed

- An assistant answer that only proposed changes, with no text, is kept
  in the conversation sent back (as a short placeholder), so the
  decision's turn does not follow the question directly.
- The assistant's drawer trims the conversation it sends by the UTF-8
  bytes of the whole request body, 1 KiB under the server's 64 KiB
  (`ASSISTANT_MAX_BODY_BYTES`, checked against
  `AssistantController::MAX_BODY` by the test suite), instead of by
  JavaScript's string length; a long Hebrew, Arabic or CJK conversation
  no longer gets a 422 `too_large` on every later message.
- An assistant answer longer than the server's 8,000-character
  per-message limit (`ASSISTANT_MAX_MESSAGE_CHARS`, checked against
  `AssistantController::MAX_MESSAGE` by the test suite, counted in code
  points as the server's `mb_strlen` counts them) is cut to that length,
  ending with `…`, in the conversation sent back, instead of being
  replayed whole; one long answer no longer makes every later message,
  Retry or approval a 422. It is cut rather than dropped so the turns
  still alternate.
- A message of the person's own longer than that limit is not sent and
  not added to the conversation, where every later turn would replay it
  and be refused: it is left in the input to shorten, saying why
  (`ai.assistant.tooLong`).
- A trimmed conversation opens with the person's own message: the
  answers and decisions before it are dropped, so a provider such as
  Bedrock does not refuse it.

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
- Writing help: under the `string` and `text` inputs of a resource's
  create page and of a record's edit page, and not in a list's bulk edit
  or an action's dialog (`defineInputAddon`), a menu — Rewrite, Shorten, Fix grammar, Translate
  to… — whose answer shows beside the text, Before and After, with
  Replace (into the input, for the person to save) and Discard; and the
  same menu in the rich-text and Markdown editors' toolbars
  (`defineEditorToolbarItem`), acting on the selection (its plain text,
  or its Markdown source), disabled while nothing is selected, and
  replacing that selection as text unless it changed meanwhile. A
  failure leaves the text as it was and says why; a Markdown answer is
  shown through the admin's own sanitizer. Shown only while
  `capabilities.ai.writing` is on and neither the resource
  (`excluded`) nor the field (`excludedFields`) is kept from AI. The
  editor toolbar item, like the input addon, shows only on its
  resource's create or edit page, for that resource's own field: not in
  a bulk edit, an action's dialog or a relation's inline create.
- Approval cards: a change the assistant proposed (a `data-approval`
  frame, or the JSON answer's `approvals`) shows under its answer as a
  card built from the structured proposal — the tool, the resource, the
  record and each field, with its value before where a `vector_get` of
  the same run read it, and when it expires — never from the model's
  prose. Approve and Reject post to `/vector/ai/approvals/{id}/approve`
  and `/reject` with no body; a decision that stands (also one the
  server reports as already made, 409) is sent back as an `approval`
  message so the assistant says what happened, and an expired (410) or
  unknown (404) proposal says so on its card.
