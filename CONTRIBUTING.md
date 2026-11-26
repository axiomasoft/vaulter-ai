# Contributing to Vaulter AI

## Repository layout

| Path | What |
| --- | --- |
| `packages/{core,documents,sheets,collaboration,laravel-ai,agentools}` | PHP packages, one Composer package each |
| `packages/vue` | `@vaulter/ai-vue` (npm workspace) |
| `clients/python` | `vaulter-office-client` |
| `tests/` | Cross-package tests: architecture, boot, the consumer stack in `tests/consumers/office` |
| `scripts/dev` | Local helpers (`prepare-siblings.sh`) |
| `scripts/ci` | CI-only gates (migration replay, unindexed foreign keys, SQL stop-rail) |
| `release/packages.json` | Artifact manifest of the private release channel |

## Prerequisites

- PHP 8.4 or 8.5 with Composer
- Node.js 26.13.1 or newer with npm
- Python 3.12 or newer (for the client)
- The sibling checkouts the Composer path repositories point to, next to this one:

```text
workspace/
├── vaulter-ai/              (this repository)
├── vaulter/                 axiomasoft/vaulter
├── vaulter-sheets/          axiomasoft/vaulter-sheets
├── vaulter-collaboration/   axiomasoft/vaulter-collaboration
├── agentools/               agentools SDK
└── packman/                 axiomasoft/packman
```

`scripts/ci/checkout-ecosystem.sh` clones any missing sibling (branch `main`,
packman at the tag pinned in `composer.json`); set `VAULTER_READ_TOKEN` to a
token that can read the private repositories.

## PHP

```bash
composer install
composer prepare     # builds the sibling Node artifacts the sheets tests execute (no-op when fresh)
composer test        # prepare + the whole Pest suite on in-memory SQLite
composer quality     # composer validate, Pint, PHPStan
composer lint        # apply Pint
```

`composer prepare` runs `scripts/dev/prepare-siblings.sh`. It builds
`@vaulter/vue`, the sheets runtime, realtime and Vue packages and installs
Playwright Chromium, each step only when its sources changed. Set
`VAULTER_SKIP_PREPARE=1` when the artifacts are provided another way and
`VAULTER_SIBLINGS_ROOT` when the siblings live elsewhere.

Run one suite or one file:

```bash
vendor/bin/pest --testsuite=core
vendor/bin/pest packages/documents/tests/TextProjectionTest.php
```

### PostgreSQL

The default run uses SQLite. CI also runs everything against PostgreSQL 18 and
19; reproduce it with any local server:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=vaulter_ai_test \
DB_USERNAME=vaulter DB_PASSWORD=vaulter vendor/bin/pest
```

The database name must end with `_test`.

## Vue

```bash
npm ci
npm run typecheck -w @vaulter/ai-vue
npm test -w @vaulter/ai-vue
npm run build -w @vaulter/ai-vue
```

## Python client

```bash
cd clients/python
uv sync --group dev            # or: pip install -e . ruff mypy
ruff check . && ruff format --check .
mypy
python -m unittest discover -s tests
```

The client has no runtime dependencies; keep it that way.

## CI

The quality job validates the workspace and package Composer manifests, runs Pint and PHPStan on PHP 8.4, checks workflow definitions, and exercises the license and source rules against their negative fixtures.

After it passes, PHP 8.4 and 8.5 run the schema and package suites on PostgreSQL 18.7 and 19.0 and SQLite. The matrix also checks PHP 8.5 types. Parallel Node and Python jobs cover the Vue build, office client tests and static checks. Branch protection requires the final `CI gate` check.

## Conventions

- Conventional Commits with the package as scope (`fix(core): …`,
  `test(sheets): …`, `build(python-client): …`). The body explains what changed
  and why; reviewers read it.
- `declare(strict_types=1)` everywhere; style is the family Pint preset
  (`pint.json`); PHPStan must stay at zero errors without a baseline.
- A package may only use namespaces its own `composer.json` provides; the
  architecture tests in `tests/Arch` enforce the layering.
- Every behaviour change comes with a test that fails without it.
- Documentation is English only.
