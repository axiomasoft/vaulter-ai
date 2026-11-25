# axiomasoft/vaulter-ai-sheets

Owner for Vaulter Sheets. It exposes the workbook catalog and bounded
table slices (`sheet_catalog`, `sheet_table` selectors) and implements
`sheet.fill_formula@1`: fill a column with a formula for a fixed set of rows
or for the rows current at commit time, then verify formulas and calculated
values.

## Installation

```bash
composer require axiomasoft/vaulter-ai-sheets
php artisan vendor:publish --tag=vaulter-ai-sheets-config
```

`Vaulter\Ai\Sheets\SheetsAiServiceProvider` is auto-discovered.

## Notes

- Verification waits for recalculation and returns `calculation_pending`
  until values are available.
- The test suite executes the real spreadsheet runtime and reopens a committed
  workbook in headless Chromium; run `composer prepare` in the repository root
  first.

## Documentation

- [Architecture](../../docs/concepts/architecture.md)
- [Configuration](../../docs/reference/configuration.md)
- [Changelog](CHANGELOG.md)

Proprietary. Part of the [Vaulter AI](../../README.md) monorepo.
