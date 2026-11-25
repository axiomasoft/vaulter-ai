# axiomasoft/vaulter-ai-agentools

Executor on the AgenTools SDK. `OfficeToolPort` and
`OfficeToolDescriptorRegistry` expose the Office operations as AgenTools tools
(read, draft and confirmed-write action profiles), and
`AgenToolsOfficeExecutor` runs AgenTools scenarios as resumable runs. It also
provides an `LlmPort` that talks to OpenAI Responses, Chat Completions or
Anthropic Messages compatible endpoints.

## Installation

```bash
composer require axiomasoft/vaulter-ai-agentools
php artisan vendor:publish --tag=vaulter-ai-agentools-config
```

`Vaulter\Ai\AgenTools\AgenToolsServiceProvider` is auto-discovered.

## Notes

- Registered as executor `agentools`; select it per profile with
  `vaulter-ai.profiles.<name>.executor`.
- `lease_seconds` must outlast the slowest scenario, otherwise another worker
  may claim the run.

## Documentation

- [Architecture](../../docs/concepts/architecture.md)
- [Configuration](../../docs/reference/configuration.md)
- [Changelog](CHANGELOG.md)

Proprietary. Part of the [Vaulter AI](../../README.md) monorepo.
