# Changelog

Spanish version: [CHANGELOG.es.md](CHANGELOG.es.md)

All notable changes to this project will be documented in this file.

The project follows [Semantic Versioning](https://semver.org/).

## [3.0.0] - 2026-09-18

**v3.0.0 - Fiscal Precision and Integrity**

A major release because of incompatible changes to public VAT semantics, accounting precision, and discount value types. This is not a 2.x patch or minor update.

### Added

- `FiscalCalculator` as the central internal authority for fiscal strategies and catalog validation.
- Configurable monetary precision from 0 to 4 decimals and v3 snapshots with explicit precision.
- `Money::compare()` for exact decimal comparisons of numeric strings, including scientific notation; support for these values without losing digits in Money and discounts. Floats remain approximate and are normalized to 15 significant digits.
- Semantic validation of persisted costs, discounts, and observations, shared by session metadata and snapshots.
- Validation of `fixedUnits` through integer states reachable across successive precision changes within 0..4.
- Atomicity coverage for `restore()` and `merge()` when product, metadata, or snapshot validation fails.
- CI coverage for Laravel/Illuminate 10, 11, and 12 across the ten supported PHP 8.1–8.4 combinations; `git diff --check HEAD^ HEAD` checks committed Git changes.

### Changed

- `CartItem::tax` represents VAT for the entire row; `taxTotal` presents that same VAT. `unitTax` and `priceTax` retain per-unit semantics.
- GENERAL quantizes the complete `qty × price` base, calculates HALF_UP VAT per row, and sums by tax category.
- PNP calculates VAT on the complete unquantized base (`raw qty × price`), truncates per row, and sums individual taxes.
- HKA groups quantized bases by tax category, calculates grouped HALF_UP fiscal VAT, and reconciles individual taxes within each category. Positive differences go to the highest provisional VAT; negative differences are subtracted in order without taking any row below zero. Ties use the largest base, then the lexicographically smallest rowId.
- `cart.format.decimals` controls monetary/fiscal arithmetic and presentation, not just formatting. Quantization, allocation, and reconciliation operate in integer minor units; float inputs do not acquire arbitrary decimal precision.
- Fixed discounts retain `fixedUnits`; `discount.value` preserves the requested `int|float|numeric-string` type and exact strings. Precision changes rescale units without reconstructing lost information from `value`.
- V3 snapshots and metadata preserve scale. `restore()`/`merge()` validate integrity before incorporating data, and `merge()` simulates all quantity accumulations before mutating the session.
- The catalog requires exactly keys 0, 1, 2, and 3, nonempty names unique ignoring case and surrounding whitespace, and numeric, finite, nonnegative rates.

### Fixed

- Incorrect rejection of legitimate `fixedUnits` after successive rounding, such as `12345 → 1235 → 124` along 4 → 3 → 2; historically impossible units remain rejected.
- Precision loss from converting discount numeric strings to floats, and artificial boundary crossings during monetary quantization.
- Acceptance of tiny negative numeric strings such as `-1e-9999` in prices, costs, and discounts, and percentages exactly above 100 that a float could not distinguish.
- Invalid quantity accumulations in `add()`, `addCartItem()`, `merge()`, and identity merges in `update()`, detected before modifying products or discounts or emitting success events.
- Acceptance of corrupt products or metadata by `restore()`/`merge()`, and partial mutations when the destination was invalid.
- Silent omission of lines with invalid tax categories; incomplete catalogs and unknown aliquots in snapshots raise controlled exceptions.
- Negative individual taxes during HKA reconciliation, PNP recalculation on grouped bases, and GENERAL calculations using unit VAT multiplied by quantity.

### Compatibility

- Laravel/Illuminate 10, 11, and 12 remain supported, with PHP 8.1 as the minimum; Laravel 11/12 require PHP >=8.2 through their dependencies.
- No mandatory database migration. Valid legacy and v2 snapshots remain restorable; v2 retains its historical two-decimal precision, and historical rowIds are not regenerated during restore.
- V3 snapshots include explicit precision and must not be consumed by older package versions.
- The catalog must contain exactly 0/1/2/3. Configurations removing categories or adding extra fiscal keys are no longer valid; `name` and `value` remain configurable within the stated rules.

### Upgrade Notes

Review 2.x integrations that calculated:

```php
// Before: this would now multiply row VAT a second time.
$totalTax = $item->tax * $item->qty;
// In 3.x:
$totalTax = $item->tax;
```

`taxTotal` already corresponds to the row; do not multiply it by quantity again. Review expected GENERAL/PNP/HKA totals, configured precision, and `cart.taxes`. Use `summary()` for fiscal settlement with costs and discounts.

`$discount['value']` may be a string when a numeric string was supplied: do not assume `is_float($discount['value']) === true` or require float through strict type declarations without an explicit conversion. That conversion can lose precision; retain the original value for exact monetary decisions.

See the [2.x to 3.x upgrade guide](docs/adjustments.md#upgrading-from-2x-to-3x) before deploying.

## [2.0.1] - 2026-09-16

### Fixed

- Corrected the PHP and Illuminate compatibility constraints declared by the package.
- Removed obsolete Laravel 7, 8, and 9 compatibility declarations.
- Aligned runtime and development Illuminate dependencies with the versions actually supported by the package.

### Added

- Official compatibility with Laravel / Illuminate 12.
- Automated compatibility testing across supported PHP and Illuminate versions using GitHub Actions.

### Compatibility

The package is now automatically tested with the following compatibility matrix:

- Laravel / Illuminate 10:
  - PHP 8.1
  - PHP 8.2
  - PHP 8.3
  - PHP 8.4
- Laravel / Illuminate 11:
  - PHP 8.2
  - PHP 8.3
  - PHP 8.4
- Laravel / Illuminate 12:
  - PHP 8.2
  - PHP 8.3
  - PHP 8.4

PHP 8.1 remains the minimum package requirement. Laravel / Illuminate 11 and 12 require PHP 8.2 or later.

## [2.0.0] - 2026-09-15

### Added

- Persistent ITEM and PRORATED additional costs.
- Tip support with structured observations.
- Line-level and document-level discounts.
- Fixed and percentage discount types.
- `Cart::summary()` as the source of the final fiscal settlement.
- Structured fiscal product lines and ITEM cost lines.
- Final taxable bases grouped by aliquot.
- Safe cart line resolution by `rowId` or unambiguous product code.
- `getByRowId()` and `getById()` lookup methods.
- Persistent adjustment metadata for costs, discounts, and observations.
- Versioned cart snapshots containing products and adjustment metadata.
- Exact proportional distribution of costs and discounts using integer cents.
- Extended fiscal driver test coverage.

### Changed

- Cart line identity now consists of product code/id, options, and aliquot.
- Generated `rowId` values now include the aliquot as part of line identity.
- `add()` accumulates quantity only when the complete line identity matches.
- `update()` is the recommended operation for modifying an existing cart line.
- `subtotal()` now represents the final adjusted base and includes ITEM costs.
- GENERAL calculates tax for each final fiscal line and then sums line taxes by aliquot.
- HKA groups final bases by aliquot before calculating tax.
- PNP truncates product line bases, groups them by aliquot, and truncates the resulting tax.
- ITEM costs participate in fiscal settlement according to the configured driver.
- PRORATED costs are incorporated into product bases before discounts and taxes.
- Stored carts now use a versioned structure containing both content and metadata.
- `destroy()` also removes adjustment metadata.

### Compatibility

- Applications must not generate, reconstruct, or predict `rowId` values.
- Product code lookup is supported only when the code identifies a single cart line; ambiguous codes require `rowId`.
- Existing `addCost($name, $price)` calls without a mode retain their legacy behavior.
- The current version can restore legacy stored carts containing product collections only.
- Older package versions do not understand the new versioned snapshot structure.
- Applications generating invoices with adjustments should use `Cart::summary()` instead of rebuilding final bases from `Cart::content()`.
- `totalCost()` and `totalDiscount()` are informational components and must not be applied again to `Cart::total()`.

### Upgrade Notes

This is a major release because some existing behaviors may require changes in consuming applications.

Before upgrading from 1.x:

- Stop generating or persisting assumptions about calculated `rowId` hashes.
- Review code that uses `subtotal()`, as it now includes applied adjustments and ITEM costs.
- Use `summary()` when generating fiscal documents with costs or discounts.
- Do not add `totalCost()` to `total()` or subtract `totalDiscount()` from it.
- Review fiscal totals if the application depends on GENERAL, HKA, or PNP rounding behavior.