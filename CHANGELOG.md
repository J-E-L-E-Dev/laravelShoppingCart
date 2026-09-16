# Changelog

Spanish version: [CHANGELOG.es.md](CHANGELOG.es.md)

All notable changes to this project will be documented in this file.

The project follows [Semantic Versioning](https://semver.org/).

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