# Changelog

All notable changes to `kinetis/vector-ai` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-19

### Added

- The package, with its first feature, plain-language filters:
  `POST /vector/ai/filters/{resource}` turns a sentence into the list's
  own filter grammar (`filter`, `groups`, `q`), validated by Vector's
  list validator with one repair turn, with a relation named in words
  looked up under the user's scope (`unresolved` when several records
  match), a 30-second deadline per provider call, and a usage row per
  call; `Governance\ModelBoundary`, the one place that decides what a
  model is told (never password, file or image fields or what
  `viewField` hides; `ai.send` metadata opts a resource or field out, or
  in under the `allowlist` setting); `capabilities.ai.filters` on, and
  `capabilities.ai.excluded` naming the resources kept from the model.
  The foundations under it: `AiConfig` (the operator's
  `VECTOR_AI_*` configuration, checked at boot), `AiTransports` (one
  `NeuronHttpClient` per provider origin, one `SigV4SigningClient` per
  Bedrock region), `SecretBox` and `CredentialStore` (provider keys
  sealed under `VECTOR_AI_KEY` for their provider, and `GET`, `PUT` and
  `DELETE /vector/ai/credentials`, which record each `set` and `delete`
  in Vector's audit trail without the key), the `ai` settings group, `AiAccessInterface`,
  `ProviderResolver`, the `ai` shell contributor (every feature off whenever computing it fails),
  `AiFailure` and `AiFailureMapper` (a provider failure by category and
  status, logged with those two only), the `UsageLedger`,
  `Testing\ScriptedProvider` (the `fake` provider type, chat, stream and
  structured calls alike, with `ScriptedCalls` to read what it was sent)
  and `vector-ai:install`. The UI half,
  `@kinetis/vector-ai-ui`, is delivered through Nuxt `extends`; hosted
  (no-build) delivery of it comes in a later release.
