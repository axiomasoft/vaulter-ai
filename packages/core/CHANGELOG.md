# Changelog

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

### Fixed

- `POST /operations` refuses a body above 1 MiB with `413 {"error":"request_too_large"}`
  before decoding it; there was no bound.
- Executors are registered lazily, so the providers boot (and `artisan key:generate`
  or static analysis run) without an `APP_KEY`.
- Workspace form posts redirect only to same-host paths, and the fallback follows
  `workspace_prefix`.
- Declare `illuminate/bus` and `illuminate/queue`, which the queued jobs use.

### Changed

- Office services and custom registry extensions are scoped to a request or job. Register host extensions with the per-resolution hook in the architecture guide.
- HTTP controllers receive their current dependencies through Laravel action injection, including when the route reuses a controller instance. Hosts that invoke controller methods directly must use the updated signatures.

- `ConfigProviderSecretStore` depends on Laravel's `StringEncrypter` contract.
