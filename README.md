# vaulter-ai

Vaulter AI — Office AI SDK v1, document, sheet and collaboration adapters, P/Q approval, resumable SSE AI chat, Laravel AI and AgenTools executors, and the Python client.

Private / proprietary channel for FlexCrm and Edufy.

## Layout

| Path | Artifact | Role |
| --- | --- | --- |
| `packages/core/` | `axiomasoft/vaulter-ai-core` | Office SDK v1 and resumable AI chat |
| `packages/documents/` | `axiomasoft/vaulter-ai-documents` | Single-document text adapter |
| `packages/sheets/` | `axiomasoft/vaulter-ai-sheets` | Spreadsheet adapter |
| `packages/collaboration/` | `axiomasoft/vaulter-ai-collaboration` | Live-text adapter |
| `packages/laravel-ai/` | `axiomasoft/vaulter-ai-laravel-ai` | Laravel AI executor |
| `packages/agentools/` | `axiomasoft/vaulter-ai-agentools` | Optional AgenTools executor |
| `packages/vue/` | `@vaulter/ai-vue` | P/Q approval and chat panel |
| `clients/python/` | `vaulter-office-client` | Stdlib Python HTTP client |
| `release/packages.json` | — | Private PHP ZIP and npm tgz manifest |

The root workspace uses the Composer path repository `packages/*` and npm workspaces.

Install `axiomasoft/vaulter-ai-collaboration` alongside AI Documents for live text. Laravel discovers `CollaborationAiServiceProvider` automatically. Hosts that register providers manually should include it with the Vaulter Collaboration provider.

## Toolchain

- PHP `>=8.4 <8.6`
- Node.js `>=26.13.1` (`package.json` `engines.node`)
- PostgreSQL CI matrix: 18.7 and 19.0

## CI

`.github/workflows/ci.yml` validates `composer.json`, asserts Node.js 26.13.1, runs PostgreSQL 18.7 and 19.0, applies the unindexed-foreign-key gate, and fails when a dependency or a source file hits a stop-crane. The workflow's negative fixture must fail on a forbidden package or a banned call.
