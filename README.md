# Vaulter AI

Approval-gated AI editing for Vaulter documents and spreadsheets.

Vaulter AI gives a language model a small, versioned protocol — the
**Office SDK v1** — instead of database or storage access. The model searches
and reads bounded slices, prepares a proposal whose digest pins the exact
change, waits for a person (or a policy) to approve it, commits it
idempotently and verifies the result. Chat runs stream to the browser over
resumable Server-Sent Events.

Private channel for FlexCrm and Edufy.

## Packages

| Path | Artifact | Role |
| --- | --- | --- |
| [`packages/core`](packages/core/README.md) | `axiomasoft/vaulter-ai-core` | Office SDK v1, proposals, runs, chat, HTTP endpoints |
| [`packages/documents`](packages/documents/README.md) | `axiomasoft/vaulter-ai-documents` | Owner for Vaulter Documents text |
| [`packages/sheets`](packages/sheets/README.md) | `axiomasoft/vaulter-ai-sheets` | Owner for Vaulter Sheets tables |
| [`packages/collaboration`](packages/collaboration/README.md) | `axiomasoft/vaulter-ai-collaboration` | Live-text owner on top of documents |
| [`packages/laravel-ai`](packages/laravel-ai/README.md) | `axiomasoft/vaulter-ai-laravel-ai` | Executor on `laravel/ai` |
| [`packages/agentools`](packages/agentools/README.md) | `axiomasoft/vaulter-ai-agentools` | Executor on the AgenTools SDK |
| [`packages/vue`](packages/vue/README.md) | `@vaulter/ai-vue` | Proposal review, chat panel, Tiptap extensions |
| [`clients/python`](clients/python/README.md) | `vaulter-office-client` | Stdlib Python client (sync and async) |

## Documentation

Start with [docs/index.md](docs/index.md):
[installation](docs/getting-started/installation.md),
[architecture](docs/concepts/architecture.md),
[protocol](docs/concepts/office-protocol.md),
[host integration](docs/guides/host-integration.md),
[configuration](docs/reference/configuration.md),
[HTTP API](docs/reference/http-api.md),
[errors](docs/reference/errors.md),
[security](docs/security/overview.md).

## Requirements

- PHP `>=8.4 <8.6`, Laravel 13
- PostgreSQL 18 or 19 (CI runs both)
- Node.js `>=26.13.1` for `@vaulter/ai-vue`
- Python `>=3.12` for the client

## Development

```bash
composer install
composer test       # builds sibling artifacts when stale, then runs Pest
composer quality    # composer validate, Pint, PHPStan
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the sibling checkouts, PostgreSQL,
Vue and Python commands, and [SECURITY.md](SECURITY.md) for reporting
vulnerabilities.

## License

Proprietary; see [LICENSE](LICENSE).
