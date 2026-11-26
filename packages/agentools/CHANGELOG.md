# Changelog

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

### Changed

- `OfficeToolPort::forContext()` accepts an optional resource list. Omitting it or passing `null` uses the caller’s authorized scope. An explicit empty list admits no tools. A nonempty list admits only the given resources and excludes `office_search`. Calls outside the scope return `resource_unavailable`.

- The adapter and its Vaulter dependencies require matching package versions.
- Executor instances are scoped to the current request or queue job.

### Fixed

- `channel` and `lease_seconds` from `vaulter-ai-agentools` are applied to
  dispatched runs.
- The executor is built on first use instead of while the provider boots.
