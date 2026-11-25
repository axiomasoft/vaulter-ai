# Changelog

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

### Fixed

- Concurrent `AsyncOfficeClient` calls no longer send each other's credentials.
- A 2xx response that is not a JSON object raises `OfficeProtocolError`.
- Endpoints other than `http`/`https` are rejected at construction.

### Added

- `py.typed`; the package is checked with `mypy --strict` and ruff.
