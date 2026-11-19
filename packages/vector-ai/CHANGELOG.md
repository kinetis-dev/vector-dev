# Changelog

All notable changes to `kinetis/vector-ai` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-19

### Added

- The package, with no AI feature yet: `AiConfig` (the operator's
  `VECTOR_AI_*` configuration, checked at boot), `AiTransports` (one
  `NeuronHttpClient` per provider origin, one `SigV4SigningClient` per
  Bedrock region), `SecretBox` and `CredentialStore` (provider keys
  sealed under `VECTOR_AI_KEY` for their provider, and `GET`, `PUT` and
  `DELETE /vector/ai/credentials`, which record each `set` and `delete`
  in Vector's audit trail without the key), the `ai` settings group, `AiAccessInterface`,
  `ProviderResolver`, the `ai` shell contributor (every feature off, and off whenever computing it fails),
  `AiFailure`, the `UsageLedger`, `Testing\ScriptedProvider` (the `fake`
  provider type) and `vector-ai:install`. The UI half,
  `@kinetis/vector-ai-ui`, is delivered through Nuxt `extends`; hosted
  (no-build) delivery of it comes in a later release.
