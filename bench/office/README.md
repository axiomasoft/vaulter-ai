# Office assignment cost (`5.2`)

Compare two ways of giving one scripted office assignment its context, on the same synthetic contract and workbook, the same command, the same fake model, and the same calculator.

| Branch | What the model is allowed to see |
| --- | --- |
| `full_snapshot` | The caller’s whole contract and workbook snapshot, when that UTF-8 payload fits the first-profile byte limit. A wide `text_outline` and an unfiltered `sheet_table` read are timed as discovery. |
| `sdk_selection` | Only the addressed clause (`text_block`) and the two writable rows (`sheet_table` + `row_ids`). The snapshot is not loaded for the model. |

The write is always `sheet.fill_formula@1` / `fixed_members` on `r-telecom` and `r-travel` (`actual − plan`). `r-fees` stays `77`. Both branches must verify `passed` at `at_commit` and `current`. A mismatched truth sets `comparison_valid` to `false`; the numeric comparison is then not a verdict.

`1×` is 11 contract blocks and 3 sheet rows. `10×` is 110 blocks and 30 rows. Thirty rows exceed the first-profile page of 20, and the larger contract exceeds 16 KiB, so the full branch must narrow with an explicit selector instead of treating a truncated page as the write area. This corpus is the office assignment, not the storage physical-schema corpus (1 100 blocks / 100 000 cells) measured by `vaulter`’s `plan/checks/storage-benchmark.sh`.

## First profile

The bench requests these stand limits and reads the live `vaulter-ai.limits` config. A mismatch with `workload.json` makes `comparison_valid` false.

- search page: 5
- targeted read: 20 rows and 16 KiB (`16384` bytes)
- prepare targets: 20

`limit_exceeded`, a truncated page, or an over-budget payload is not model context and is not a prepare token. The bench repeats the read with the explicit clause and the two target row ids, then checks that this smaller read is complete.

## Metrics

Each branch records cold and warm reads separately (`temperature`). Warm repeats only the admitted context. `p50_ms` / `p95_ms` use nearest-rank `ceil(p·n)`. Below 20 samples `p95_confidence` is `indicative`.

Also recorded: SQL query count, HTTP request count and request+response body bytes, buffer/disk counters, scripted model calls, write duration, publication lag, text-index rebuild, and verify duration.

PostgreSQL reports `pg_stat_database.blks_read` / `blks_hit`. SQLite has no shared buffers; those fields are `status: unmeasured` and are not filled with zeroes.

The model is `fake-office-scripted`. It does not call a provider. `tokens.provider_*` and `tokens.tokenizer_estimate_*` are `status: unmeasured`. Measured size is `utf8_bytes` only. A live provider key is not read and no provider tokens are invented.

`optimization.reduced` is true when the SDK branch lowers model-context UTF-8 bytes or cold-read SQL. When it does not, `optimization.section` names the section and `working_configuration` records the engine, profile, scale, and admitted selectors.

## Run

From the `vaulter-ai` root, after `composer install`:

```bash
php bench/office/compare.php --output=bench/office/results/latest.json
php bench/office/compare.php --warmup=0 --measured=20 --output=/tmp/office-cost.json
```

`--measured` below 1 is rejected. The process exits `0` when the report is written and `comparison_valid` is true, `3` when the report is written but the truth does not match, and `2` on usage errors.

The Pest proof uses the same class with a short iteration override:

```bash
vendor/bin/pest -c phpunit.xml.dist packages/sheets/tests/OfficeCostBenchTest.php
```

When `APP_KEY`, `APP_ENV`, `DB_CONNECTION`, `DB_DATABASE`, `CACHE_STORE`, and `QUEUE_CONNECTION` are unset, the CLI uses the same testing defaults as `phpunit.xml.dist` (SQLite `:memory:` and the suite’s public test key). Set `DB_CONNECTION=pgsql` and the suite’s `PGSQL_*` variables to measure shared-buffer counters. The CLI does not call PHPUnit’s teardown.
