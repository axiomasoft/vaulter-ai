# axiomasoft/vaulter-ai-core

The Office SDK v1: wire protocol and JSON Schema, proposal digests, the
`OfficeRegistry` owners and executors plug into, proposals, executor runs,
chat sessions, the operation endpoint and the workspace endpoints.

It depends on Laravel components only and knows nothing about Vaulter storage
or a model SDK; install at least one owner and one executor next to it for a
working assistant.

## Installation

```bash
composer require axiomasoft/vaulter-ai-core
php artisan vendor:publish --tag=vaulter-ai-config
```

`Vaulter\Ai\Core\OfficeServiceProvider` is auto-discovered.

## Notes

- `Contracts\Office::handle(CallContext, OfficeRequest)` — the PHP entry point,
  shared by HTTP and background jobs.
- `POST /api/vaulter-ai/v1/operations` and the `/office/...` workspace routes
  (see [HTTP API](../../docs/reference/http-api.md)).
- `resources/contracts/office-v1.schema.json` — the wire contract.
- `php artisan vaulter-ai:secrets:rotate` — re-encrypt stored provider keys.

Bind a `CallContextResolver` before anything else; the default one
authenticates nobody. See [Host integration](../../docs/guides/host-integration.md).

## Documentation

- [Architecture](../../docs/concepts/architecture.md)
- [Configuration](../../docs/reference/configuration.md)
- [Changelog](CHANGELOG.md)

Proprietary. Part of the [Vaulter AI](../../README.md) monorepo.
