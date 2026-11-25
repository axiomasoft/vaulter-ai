# axiomasoft/vaulter-ai-laravel-ai

Executor on [`laravel/ai`](https://github.com/laravel/ai). `OfficeAgent`
exposes the seven Office operations as tools, `ExecuteOfficeRun` runs the
model loop on a queue after the surrounding transaction commits, and every
step is persisted so a run can be resumed. It also provides the `LlmPort`
used by chat.

## Installation

```bash
composer require axiomasoft/vaulter-ai-laravel-ai
php artisan vendor:publish --tag=vaulter-ai-laravel-ai-config
```

`Vaulter\Ai\LaravelAi\LaravelAiServiceProvider` is auto-discovered.

## Notes

- Registered as executor `laravel-ai`; select it per profile with
  `vaulter-ai.profiles.<name>.executor`.
- Keep `tool timeout < worker timeout < queue retry_after`
  (see [Installation](../../docs/getting-started/installation.md#queue)).

## Documentation

- [Architecture](../../docs/concepts/architecture.md)
- [Configuration](../../docs/reference/configuration.md)
- [Changelog](CHANGELOG.md)

Proprietary. Part of the [Vaulter AI](../../README.md) monorepo.
