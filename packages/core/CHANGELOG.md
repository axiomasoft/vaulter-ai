# Changelog

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

### Fixed

- Executors are registered lazily, so the providers boot (and `artisan key:generate`
  or static analysis run) without an `APP_KEY`.
- Workspace form posts redirect only to same-host paths, and the fallback follows
  `workspace_prefix`.
- Declare `illuminate/bus` and `illuminate/queue`, which the queued jobs use.

### Changed

- `ConfigProviderSecretStore` depends on Laravel's `StringEncrypter` contract.
