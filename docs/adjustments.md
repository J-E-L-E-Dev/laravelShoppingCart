# Costs, Discounts, and Fiscal Settlement

This guide documents the cart's advanced settlement features: additional costs, prorated costs, discounts, observations, monetary precision, fiscal calculations, and persistence.

For general usage of `Cart::add()`, `Cart::update()`, line identity, `rowId`, and basic fiscal driver configuration, see `README.md` or `README.es.md`.

---

## Additional Costs

The package allows additional document-level costs to be registered using:

```php
Cart::addCost($name, $price, $mode = null, $aliquot = null, $description = null);
```

In PHP 8+, `amount` can also be used as a named argument:

```php
Cart::addCost(
    name: 'packaging',
    amount: 10,
    mode: 'item',
    aliquot: 1
);
```

Costs are stored as independent operations. Multiple operations with the same name may coexist.

The main modes are:

* `ITEM`
* `PRORATED`

Tips have special handling, while costs registered without a mode retain the legacy behavior for backward compatibility.

Constants available in `JeleDev\Shoppingcart\Cart`:

```php
Cart::COST_FREIGHT;
Cart::COST_INSTALLATION;
Cart::COST_PACKAGING;
Cart::COST_INSURANCE;
Cart::COST_TIP;

Cart::COST_ITEM;
Cart::COST_PRORATED;
```

`COST_TRANSACTION` is also preserved, and custom names may be used.

### ITEM Cost

An `ITEM` cost represents an independent billable concept.

Example:

```php
Cart::addCost(
    'installation',
    20,
    Cart::COST_ITEM,
    0,
    'Installation'
);
```

The cost requires an explicit aliquot defined in `config('cart.taxes')`.

An ITEM cost:

* increases the subtotal;
* becomes part of the taxable base;
* may generate tax according to its aliquot;
* appears in `summary()['costLines']`;
* is not internally converted into a `CartItem`.

For fiscal purposes, it behaves as an additional document line.

Its tax treatment depends on the configured driver:

| Driver  | ITEM cost treatment                                            |
| ------- | -------------------------------------------------------------- |
| GENERAL | Calculates tax for the ITEM as an independent fiscal line      |
| HKA     | Adds its base to the accumulated base of its aliquot           |
| PNP     | Truncates VAT for each ITEM separately, then sums by aliquot |

The amount registered through `addCost()` is already quantized to configured minor units using HALF_UP. PNP truncates the ITEM's VAT, not its registered amount.

### PRORATED Cost

A `PRORATED` cost is distributed proportionally among product lines.

Example:

```php
Cart::addCost(
    'freight',
    15,
    Cart::COST_PRORATED
);
```

It does not have its own aliquot because it does not represent an independent fiscal line.

The amount is distributed among the positive original product bases, and each allocated portion inherits the aliquot of its corresponding product.

Conceptually:

```text
original product base
+ allocated PRORATED cost
= new product base
```

When multiple PRORATED costs exist, they all use the same original product bases as distribution weights.

The sum of all allocations always matches the quantized cost amount exactly.

Once incorporated into the product bases, the PRORATED cost is not added again to the subtotal or total.

A positive PRORATED cost may be registered before products are added. If no positive distributable base exists when the cart is settled, a `DomainException` is thrown.

### Tip

A tip is registered using:

```php
Cart::addCost('tip', 5);
```

A tip:

* increases the final total;
* does not increase the taxable base;
* does not generate tax;
* is not prorated;
* does not create a `CartItem`;
* does not appear as an ITEM cost;
* generates an observation of type `tip`.

It does not accept a mode or aliquot.

It can be queried separately through:

```php
Cart::summary()['tip'];
```

### Legacy Costs

For backward compatibility, the following usage is preserved:

```php
Cart::addCost($name, $price);
```

when the name does not correspond to the special tip handling and no mode is provided.

This cost retains its historical behavior:

* it increases the total;
* it does not increase the subtotal;
* it is not part of a taxable base;
* it does not generate tax.

For new integrations, explicitly specify `ITEM` or `PRORATED` whenever applicable.

### Querying Costs

The available API includes:

```php
Cart::costs();
Cart::costDetails('freight');
Cart::getCost('freight');
Cart::totalCost();
```

`costs()` returns the structured cost operations.

`costDetails()` returns operations associated with a given name. It may return multiple entries because costs of the same type can be registered more than once.

`getCost()` preserves the formatted return value for backward compatibility and represents the sum of all operations with the requested name.

`totalCost()` returns the numeric total amount of all registered costs, including tips.

> `totalCost()` is informational. It must not be added again to `Cart::total()`, because costs have already been incorporated into the settlement according to their mode.

---

## Discounts

The package supports discounts:

* on a specific line;
* on the document as a whole.

Multiple discounts may coexist and are applied in registration order.

Available types are:

```text
percentage
fixed
```

### Line Discount

A discount can be applied using an unambiguous product code:

```php
Cart::addDiscountToItem(
    'A001',
    'percentage',
    5,
    'Special discount'
);
```

or using a `rowId`:

```php
Cart::addDiscountToItem(
    $rowId,
    'fixed',
    2,
    'Line coupon'
);
```

Line discounts are applied after the PRORATED costs allocated to that line have been incorporated.

Therefore:

```text
original base
+ PRORATED
- line discounts
= line balance
```

A fixed discount applies to the entire line, not to each individual unit.

### General Discount

A general discount is registered using:

```php
Cart::addDiscount(
    'percentage',
    10,
    'Prompt payment'
);
```

or:

```php
Cart::addDiscount(
    'fixed',
    1,
    'Document coupon'
);
```

General discounts are applied after line discounts.

They are distributed proportionally among the remaining product balances.

They do not apply to:

* ITEM costs;
* tips;
* legacy costs.

### Discount Rules

A percentage must be between:

```text
0 and 100
```

A fixed discount cannot be negative.

When a fixed discount exceeds the available balance, the effective amount is capped at that balance.

Taxable bases never become negative.

The package distinguishes between:

```text
requested discount
```

and:

```text
effective discount
```

This preserves the original operation record even when the applicable amount has to be capped.

General discounts registered on a cart without products have an effective amount of zero.

### Querying Discounts

```php
Cart::discounts();
Cart::totalDiscount();
```

`discounts()` returns the registered operations and their effective amounts, including allocations by `rowId` when applicable.

`totalDiscount()` returns the numeric amount effectively discounted.

> `totalDiscount()` is informational. It must not be subtracted again from `subtotal()` or `total()`, because the settlement has already incorporated the discounts.

---

## Observations

Manual observations can be added using:

```php
Cart::addObservation('Deliver in the afternoon');
```

and queried using:

```php
Cart::observations();
```

Observations may include information generated from:

* manual observations;
* tips;
* discounts;
* consolidated discounts.

Amounts used to generate automatic observations are calculated from the current state of the document.

Observations are not the accounting source for calculations. The package never needs to parse observation text to determine costs, discounts, taxes, or totals.

---

## Settlement with `summary()`

To build the final fiscal document, use:

```php
$summary = Cart::summary();
```

`content()` continues to represent the commercial `CartItem` instances and their original values.

PRORATED costs and discounts do not destructively modify the original prices stored in those objects.

Therefore:

> When invoicing a cart that contains costs, prorated amounts, or discounts, use `summary()` as the source of the final fiscal bases.

### Product Lines

```php
$summary['lines'];
```

contains the final fiscal product lines.

Each line contains information equivalent to:

```php
[
    'rowId'     => '...',
    'id'        => 'A001',
    'original'  => 100.00,
    'prorated'  => 10.00,
    'discount'  => 15.00,
    'base'      => 95.00,
    'aliquot'   => 0,
]
```

Conceptually:

```text
original
+ prorated
- discount
= base
```

`base` is the final fiscal value that should be used for that line.

### ITEM Costs

```php
$summary['costLines'];
```

contains ITEM costs that should be treated as additional billable concepts.

These concepts remain separate from the `CartItem` instances.

### Bases by Aliquot

```php
$summary['bases'];
```

represents the final fiscal bases grouped by aliquot.

For example:

```php
[
    0 => 114.05,
    1 => 49.50,
]
```

These grouped bases are useful for inspection and fiscal document generation.

It is important to distinguish grouped bases from the strategy used to calculate tax.

In particular:

```text
GENERAL summary()['bases']
```

may be identical to:

```text
HKA summary()['bases']
```

while their taxes are different.

This is correct because GENERAL calculates tax per line, while HKA calculates tax on accumulated bases.

### Taxes

```php
$summary['taxes'];
```

uses the names configured in:

```php
config('cart.taxes');
```

Each tax contains:

```php
[
    'IVA'   => 16.00,
    'value' => 18.25,
]
```

where:

* `IVA` is the configured percentage;
* `value` is the amount calculated according to the fiscal driver strategy.

```php
$summary['tax'];
```

represents the monetary sum of all calculated taxes.

### Totals

The settlement also exposes:

```php
$summary['subtotal'];
$summary['tax'];
$summary['tip'];
$summary['totalCost'];
$summary['totalDiscount'];
$summary['total'];
```

Do not reconstruct the total by adding `totalCost()` again or subtracting `totalDiscount()` again.

These values have already been incorporated into the settlement.

---

## Mathematical Order

The settlement is conceptually performed in the following order:

1. The original base of each product is calculated:

```text
quantity × price
```

2. The subtotal base is quantized at configured precision according to the driver:

```text
GENERAL → HALF_UP
HKA     → HALF_UP
PNP     → truncate toward zero
```

PNP retains raw `quantity × price` separately for per-line VAT, including any
allocated adjustments. It does not use the truncated subtotal base as the tax input.

3. PRORATED costs are distributed proportionally among the positive original bases.

4. Allocated PRORATED costs are incorporated into product bases.

5. Line discounts are applied in registration order.

6. General discounts are applied in registration order and distributed proportionally among products.

7. Final fiscal product bases are obtained.

8. ITEM costs are incorporated as additional fiscal lines.

9. Each driver applies its tax calculation strategy.

10. Tips and legacy costs are incorporated according to their respective rules.

Conceptually:

```text
quantity × price
       │
       ▼
 original base
       │
       ▼
 + PRORATED
       │
       ▼
 - line discount
       │
       ▼
 - allocated general discount
       │
       ▼
 FINAL FISCAL BASE
       │
       ├───────────────┐
       │               │
   products         ITEM costs
       │               │
       └───────┬───────┘
               │
               ▼
       driver strategy
               │
               ▼
              tax
               │
               ▼
        subtotal + tax
               │
               ▼
      + tip + legacy
               │
               ▼
             TOTAL
```

---

## Fiscal Strategies

`cart.driver` selects the accumulation rule. `cart.format.decimals` selects
monetary/fiscal and display precision (integer 0–4, default 2). In the formulas,
`R(x)` means HALF_UP at that precision and `T(x)` means truncation toward zero.
`r` is the configured tax percentage divided by 100.

| Driver | Original product tax | Tax category authority |
| --- | --- | --- |
| GENERAL | `R(R(qty × price) × r)` | Sum rounded line taxes |
| PNP | `T((qty × price) × r)` | Sum truncated line taxes |
| HKA | Provisional `R(R(qty × price) × r)` | `R(sum(R(qty × price)) × r)` |

GENERAL and PNP never recompute tax on grouped bases. HKA alone does so.
In `summary()`, GENERAL/HKA use final quantized bases after PRORATED, line discounts
and allocated document discounts. PNP uses `max(0, qty × price + allocated PRORATED
− applied discounts)` as the tax input, then truncates tax per fiscal line.
The PNP base exposed in `subtotal` and `lines.base` remains truncated for backward
compatibility; its raw fractional remainder is retained for the tax calculation.
ITEM costs are separate fiscal lines, already quantized HALF_UP at registration.
GENERAL rounds their individual VAT, PNP truncates it, and HKA groups their bases.
PRORATED is not counted twice. Tips and legacy costs never enter bases or VAT.

Examples at precision 2 and VAT 16%:

* GENERAL, quantity 2 × price 10.23: base 20.46, line tax 3.2736 → **3.27**, not 3.28.
* GENERAL, two distinct lines of .03: tax .00 + .00 = **.00**. HKA: grouped base
  .06 gives tax **.01**. Identical `summary()['bases']` with different taxes is correct.
* PNP, two lines of .04: each tax .0064 → .00; total **.00**, not grouped .01.
* PNP, quantity 3 × price .023: raw base .069 gives tax .01104 → **.01**;
  truncating the input base to .06 first would incorrectly produce .00.

At precision 3, three distinct lines of .005 give total VAT **.003 GENERAL**,
**.002 HKA**, and **.000 PNP**.

### Original item tax and HKA reconciliation

`CartItem::tax` and the serialized `tax` field represent the whole original line.
`taxTotal` formats the same amount without multiplying by quantity. `total` is
quantized original base plus line tax. `price` remains unit price; `unitTax` is
unit VAT and `priceTax` is derived as `price + unitTax`. A manually assigned historical
`priceTax` is not a fiscal override. Separators affect formatted methods, not arithmetic.

Cart resolves taxes for the original collection and supplies each item a temporary
resolver, stored outside its serializable state in a WeakMap. The resolver holds
only a weak reference to the collection; CartItem does not query global Cart,
Session or a facade. The original collection and object identities are preserved.
`content()`, `get()`, `getById()`, `getByRowId()`, `add()` and `update()` expose the
same semantics; `toArray()`, `toJson()` and JSON collection responses agree.
The mapping is derived again on access, so quantity, driver or tax configuration
changes cannot leave a persisted tax override. A detached or standalone item has
only its provisional HKA tax; retrieve it through Cart for grouped reconciliation.
Native PHP serialization excludes the resolver and retains commercial attributes.

For HKA, independently in each tax category:

1. Compute grouped fiscal VAT in integer minor units.
2. Compute each line's provisional HALF_UP VAT in minor units.
3. Subtract the sum of provisionals from grouped VAT.
4. Assign the entire signed difference to the largest provisional tax; ties use
   the largest quantized base, then the lexicographically smallest rowId.

No residual is moved to another tax category. Zero difference does nothing.
GENERAL and PNP need no reconciliation. HKA's result is deterministic and independent
of insertion order, and its sum exactly matches the grouped authority.

Required example at precision 2:

```text
A: qty 3 × .34 = 1.02; provisional VAT .16
B: qty 1 × .89 =  .89; provisional VAT .14
Grouped VAT: 1.91 × 16% = .3056 → .31
Difference: .31 − (.16 + .14) = +.01
A.tax = .17; B.tax = .14; sum = .31
```

The specified single-recipient rule also handles negative and multi-unit differences.
It can produce a negative informational item tax: ten lines of .04 at 16% have
provisional tax .01 each, grouped VAT .06 and difference −.04; the selected item
becomes −.03. No clamping or redistribution is applied, because that would change
the specified rule. The grouped fiscal total remains .06.

Original taxes reconcile against `totalTaxes(content, [])`, **not** against
`summary()['taxes']` when costs or discounts change final bases. Adjustments never
retroactively alter original item taxes. `summary()['bases']` is always grouped
for reporting, independently of the driver's tax accumulation rule.

## Monetary Precision

`Money::decimals()` validates `cart.format.decimals`. Only integers 0–4 are allowed;
negative values, floats, strings, booleans and null are rejected. A missing setting
defaults to 2. The upper bound keeps scale at most 10000, supports the existing
1-billion single-amount limit with ample 64-bit integer margin, and avoids pretending
to provide arbitrary decimal precision. Integer overflow is rejected defensively.

`Money` centralizes:

* `scale($decimals = null)`: the single definition of `10 ** decimals`;
* `minorUnits($value, $truncate = false, $decimals = null)`;
* `fromMinorUnits($units, $decimals = null)`;
* `rescale($units, $from, $to)`: integer-only scale conversion;
* `allocate($amount, $weights)`: integer largest-remainder allocation, with lexical
  rowId tie-breaking and exact preservation of the allocated total.

`Money::cents()` remains an alias of `minorUnits()`. Historical `cents` and
`allocations` fields retain their names but represent configured minor units:
1 unit means .01 at precision 2 and .001 at precision 3. Returned amounts remain
numeric currency amounts. Compare minor units for exact assertions.

Costs (ITEM, PRORATED, tip and legacy), fixed discounts, percentage discount results,
allocations, VAT, subtotal, totals and automatic observation amounts/text all use
the configured precision. Fixed discounts preserve the requested `value` and carry
`fixedUnits` for their quantized amount; `amount`/`cents` still report the effective
capped discount. Percentage discounts keep their percentage unchanged.

Input numeric values may still be floats. Quantization normalizes binary noise in
the scaled value before HALF_UP or truncation; this is not an arbitrary-precision
decimal engine. Distribution, HKA residue application and scale conversion use integers.
`number_format()` only presents values; `decimal_point` and `thousand_separator`
do not affect arithmetic, while `decimals` now does.

### Active carts and precision changes

Session metadata records `decimals`. Data without it has historical precision 2.
Reads derive amounts at the current precision without rewriting session metadata;
the next metadata mutation stores converted operations with the current precision.
Raising precision preserves value exactly: historical 123 units at precision 2
become 1230 at precision 3, still **1.23**. Lowering precision rounds HALF_UP per
operation. Once a mutation saves that reduced precision, discarded fractions cannot
be recovered by increasing precision later. Product unit prices remain original;
their bases and taxes are recalculated at the current precision.

---

## Persistence

Products continue to be stored in the session under:

```text
cart.<instance>
```

Document metadata is stored separately under:

```text
cart_metadata.<instance>
```

Metadata includes:

```php
[
    'decimals' => 2,
    'costs' => [],
    'discounts' => [],
    'observations' => [],
]
```

This allows costs, discounts, and observations to be preserved without converting them into cart products.

### `store()`

`store()` persists the cart using a versioned envelope containing products and metadata.

Conceptually:

```php
[
    'version' => 3,
    'decimals' => 2, // precision used to encode metadata
    'content' => ...,
    'metadata' => ...,
]
```

The current version reads legacy product collections, v2 snapshots and v3 snapshots.
Both `restore()` and `merge()` interpret v2 monetary integers at historical precision
2 and rescale to the current precision. V3 requires explicit `decimals`; unknown
versions are rejected. Historical `cents = 123` becomes 1230 units at precision 3
and still means 1.23. Stored fixed discount units are converted as well; requested
values and percentage rates remain unchanged. No database migration is required.

### `restore()`

`restore()` retrieves a stored cart.

Restored products are overlaid according to the existing identity rules, and stored metadata is appended to the current metadata.

After a successful restore, the persisted record is consumed.

To completely replace the current cart before restoring another one:

```php
Cart::destroy();
Cart::restore($identifier);
```

### `merge()`

`merge()` incorporates a stored cart into the current cart.

Compatible products accumulate quantities, and metadata is appended.

When an identity changes during the merge, associated discounts are adapted to the surviving line's `rowId`.

`merge()` does not consume the persisted record.

Therefore, repeatedly executing the same `merge()` will add its quantities and metadata again.

### `destroy()`

```php
Cart::destroy();
```

removes both:

```text
cart.<instance>
```

and:

```text
cart_metadata.<instance>
```

for the active instance.

---

## Upgrade Considerations

### `rowId`

Current identities include:

```text
code/id + options + aliquot
```

For this reason, a `rowId` generated by an older version may differ from the one currently produced for an equivalent addition.

Consumer applications must not generate, reconstruct, or predict a `rowId`.

Always use the value returned by the package.

### `subtotal()`

`subtotal()` now represents the final base after adjustments and includes ITEM costs.

Consumer code that previously rebuilt the document manually should review this behavior.

### Costs and Discounts

Do not do:

```php
$total = Cart::total() + Cart::totalCost();
```

or:

```php
$total = Cart::total() - Cart::totalDiscount();
```

Adjustments have already been incorporated into `total()`.

`totalCost()` and `totalDiscount()` expose settlement components; they are not intended to be applied again.

### `content()` vs. `summary()`

`content()` represents the original commercial lines.

`summary()` represents the final fiscal settlement after:

```text
PRORATED
discounts
ITEM
tax
tip
legacy costs
```

Use `summary()` when generating an invoice that contains adjustments.

### Snapshots

Current snapshots contain products and metadata.

Legacy product-only and v2 snapshots remain readable; v3 records precision explicitly.

Older package versions do not understand the current versioned snapshot structure.

---

## Complete Reference Scenario

Assume:

```text
Product A
Original base: 100.00
Aliquot: GENERAL

Product B
Original base: 50.00
Aliquot: EXEMPT

PRORATED freight: 15.00

ITEM installation: 20.00
Aliquot: GENERAL

Line discount on A: 5%

General discount: 10%

Tip: 5.00
```

The distribution and discounts produce:

| Concept              | Product A | Product B | Installation ITEM |
| -------------------- | --------: | --------: | ----------------: |
| Original base        |    100.00 |     50.00 |             20.00 |
| PRORATED freight     |     10.00 |      5.00 |              0.00 |
| 5% line discount     |      5.50 |      0.00 |              0.00 |
| 10% general discount |     10.45 |      5.50 |              0.00 |
| Final base           |     94.05 |     49.50 |             20.00 |

Using the HKA driver:

```text
GENERAL base:
94.05 + 20.00 = 114.05

EXEMPT base:
49.50

Subtotal:
114.05 + 49.50 = 163.55

GENERAL tax at 16%:
114.05 × 16% = 18.248
→ 18.25

Tip:
5.00

Total:
163.55 + 18.25 + 5.00
= 186.80
```

Therefore:

```text
Subtotal          163.55
Tax                18.25
Tip                 5.00
-------------------------
Total              186.80
```

Registered costs total:

```text
Freight       15.00
Installation  20.00
Tip            5.00
-------------------
Total costs   40.00
```

The total effective discount is:

```text
21.45
```

These amounts are informational components of the same settlement and must not be added to or subtracted from the total again.

---

## Usage Summary

For costs:

```php
Cart::addCost('freight', 15, Cart::COST_PRORATED);
Cart::addCost('installation', 20, Cart::COST_ITEM, 0, 'Installation');
Cart::addCost('tip', 5);
```

For discounts:

```php
Cart::addDiscountToItem('A001', 'percentage', 5, 'Special discount');
Cart::addDiscount('percentage', 10, 'Prompt payment');
```

For observations:

```php
Cart::addObservation('Deliver in the afternoon');
```

To retrieve the settlement:

```php
$summary = Cart::summary();
```

When building an invoice with adjustments, primarily use:

```php
$summary['lines'];
$summary['costLines'];
$summary['bases'];
$summary['taxes'];
$summary['subtotal'];
$summary['tax'];
$summary['tip'];
$summary['total'];
```

`content()` remains appropriate for inspecting the cart's original product lines; `summary()` is the source of the final fiscal settlement.
