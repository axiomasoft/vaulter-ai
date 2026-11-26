# Changelog

All notable changes to Vaulter AI are documented here and in each package's
own `CHANGELOG.md`. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the packages
follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Resource-limited AgenTools ports now restrict both tool calls and descriptors. Pass an empty list to disable all tools or omit the list to use the caller’s authorized scope.
- Idle chat event streams poll with backoff up to two seconds, which also bounds the interval between authorization checks.

- Office services, including executor instances, are scoped to a request or job. Host extensions that register Office commands should register them for each resolution of the registry.
- Composer dependencies on other Vaulter packages require the same version across the Vaulter family. The private registry must contain matching versions of each required adapter, core and host package.

### Fixed

- `POST /operations` refuses a body above 1 MiB with `413 {"error":"request_too_large"}`
  before Office protocol decoding. Configure this limit at ingress to bound memory before Laravel builds the request.
- Providers boot without an `APP_KEY`; executors are built on first use.
- Workspace form posts no longer redirect to other hosts.
- The AgenTools executor honours its `channel` and `lease_seconds` settings.
- The async Python client no longer mixes credentials between concurrent calls.
- The Python client raises `OfficeProtocolError` for non-JSON success responses.
- `vaulter-ai-core` declares its `illuminate/bus` and `illuminate/queue` dependencies.

### Added

- Architecture tests that enforce the package layering.
- `composer prepare`, `composer test` and `composer quality` scripts.
- English documentation under `docs/`.
- Typed Python client (`py.typed`) checked with ruff and mypy in strict mode.

### Removed

- The `LiveTextOwner` class alias of the collaboration package; use
  `LiveTextCommandOwner`.
