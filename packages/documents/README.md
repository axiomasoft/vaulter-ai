# axiomasoft/vaulter-ai-documents

Owner for Vaulter Documents. It keeps an addressable text projection of
every published document (blocks with stable ids), serves authorized
`text_outline` and `text_block` reads, and implements `text.replace_exact@1`,
`text.insert_block@1` and `text.delete_block@1` with snapshot commits and
verification. Proposals can be mirrored as tracked-change suggestions in the
editor.

## Installation

```bash
composer require axiomasoft/vaulter-ai-documents
php artisan vendor:publish --tag=vaulter-ai-documents-config
```

`Vaulter\Ai\Documents\DocumentsAiServiceProvider` is auto-discovered.

## Notes

- Projections are indexed on Documents events (`projection.auto_index`).
  Rebuild them with
  `php artisan vaulter-ai:documents:rebuild-text-index <drive> [--node=] [--tenant=] [--project=]`.
- Binds `ResourceDirectory` to Vaulter Drive access checks.
- Reads with `after_receipt` fail with `projection_pending` while the
  projection lags, unless `read.live_fallback_on_lag` is enabled.

## Documentation

- [Architecture](../../docs/concepts/architecture.md)
- [Configuration](../../docs/reference/configuration.md)
- [Changelog](CHANGELOG.md)

Proprietary. Part of the [Vaulter AI](../../README.md) monorepo.
