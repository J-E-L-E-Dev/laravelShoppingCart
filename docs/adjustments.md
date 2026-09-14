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
| PNP     | Adds its base to the aliquot following the PNP fiscal strategy |

The amount registered through `addCost()` is already quantized to cents using HALF_UP. Under PNP, the original ITEM amount is not truncated again.

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

2. The base is quantized according to the fiscal driver:

```text
GENERAL → HALF_UP
HKA     → HALF_UP
PNP     → truncate toward zero
```

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

The driver configured through:

```php
config('cart.driver');
```

does not only determine whether a value is rounded or truncated.

It also determines when bases are accumulated before tax is calculated.

| Driver  | Base per line                      | Tax calculation                                                                            |
| ------- | ---------------------------------- | ------------------------------------------------------------------------------------------ |
| GENERAL | HALF_UP to 2 decimals              | Calculates and rounds tax for each final fiscal line, then sums                            |
| HKA     | HALF_UP to 2 decimals              | Groups final bases by aliquot and calculates tax on the accumulated base                   |
| PNP     | Truncate toward zero to 2 decimals | Groups truncated bases by aliquot and truncates the tax calculated on the accumulated base |

### GENERAL

GENERAL uses the entire line as the fiscal unit.

For a product:

```text
quantity × price
```

produces the initial line base.

After prorated costs and discounts, the final fiscal base is obtained.

Tax is calculated independently for each line:

```text
line tax = final line base × tax rate
```

and rounded HALF_UP to two decimal places.

The tax amounts of the lines belonging to each aliquot are then summed.

GENERAL does not calculate unit tax and then multiply the rounded result by quantity.

It also does not calculate a single tax amount over the sum of all bases in the aliquot.

### HKA

HKA quantizes each line base using HALF_UP to two decimal places.

After obtaining the final fiscal bases:

```text
line A
line B
line C
   │
   ▼
group by aliquot
   │
   ▼
accumulated base
   │
   ▼
calculate tax
   │
   ▼
HALF_UP to 2 decimals
```

ITEM costs are included in the accumulated base of their corresponding aliquot.

### PNP

PNP uses truncation toward zero when quantizing product bases.

Then:

```text
truncated bases
       │
       ▼
group by aliquot
       │
       ▼
accumulated base
       │
       ▼
calculate tax
       │
       ▼
truncate to 2 decimals
```

Registered ITEM costs are already quantized to cents by `addCost()` and participate in the base of their corresponding aliquot.

---

## Monetary Precision

Adjustment calculations use integer cents whenever possible.

The internal `Money` class centralizes:

* conversion to cents;
* HALF_UP rounding;
* truncation;
* proportional distribution;
* remainder handling.

This prevents formatted values from being used in mathematical operations.

`number_format()` and:

```php
config('cart.format');
```

are used for presentation only and do not determine accounting precision.

### Exact Distribution

PRORATED costs and general discounts may require distributing a number of cents among several lines.

The package uses proportional distribution and assigns residual cents deterministically.

The sum of all allocations always satisfies:

```text
sum of allocations
=
total quantized amount
```

When equal remainders exist, `rowId` is used as a deterministic tie-breaker.

The result does not depend on the order in which products were added to the cart.

### Floats

For backward compatibility, the API continues to accept numeric values and `float`.

Internally, `Money::cents()` reduces binary floating-point noise before quantizing values.

`float` values returned by a PHP API may expose their binary representation again when external calculations are performed.

For exact monetary assertions, comparing cents is recommended instead of direct equality between sums of `float` values.

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
    'version' => 2,
    'content' => ...,
    'metadata' => ...,
]
```

The current version can read historical records that contain only the product collection.

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

The current version can restore older snapshots containing products only.

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
