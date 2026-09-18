# Laravel Shopping Cart

The cart now supports persistent ITEM/PRORATED costs, tips, line/document discounts,
structured observations and safe lookup by product code. See the
[adjustments and migration guide](docs/adjustments.md) for the complete
API, calculation order, rounding rules and compatibility changes. Use `summary()`
for adjusted invoice bases; `content()` retains original product attributes.

### Compatibility:

[![Laravel 10.x](https://img.shields.io/badge/Laravel-10.x-red.svg)](https://laravel.com/docs/10.x)
[![Laravel 11.x](https://img.shields.io/badge/Laravel-11.x-red.svg)](https://laravel.com/docs/11.x)
[![Laravel 12.x](https://img.shields.io/badge/Laravel-12.x-red.svg)](https://laravel.com/docs/12.x)
[![Latest Stable Version](http://poser.pugx.org/edwinylil1/laravelshoppingcart/v)](https://packagist.org/packages/edwinylil1/laravelshoppingcart)
[![Total Downloads](http://poser.pugx.org/edwinylil1/laravelshoppingcart/downloads)](https://packagist.org/packages/edwinylil1/laravelshoppingcart)
[![License](http://poser.pugx.org/edwinylil1/laravelshoppingcart/license)](https://packagist.org/packages/edwinylil1/laravelshoppingcart)

## Use guide:

[![en](https://img.shields.io/badge/lang-en-red.svg)](https://github.com/J-E-L-E-Dev/laravelShoppingCart)
[![es](https://img.shields.io/badge/lang-es-yellow.svg)](https://github.com/J-E-L-E-Dev/laravelShoppingCart/blob/main/README.es.md)
Laravel Shopping Cart is a shopping cart package that allows handling different tax rates for products.

## Installation

We can add the dependency in our `composer.json` file:

```json
{
    "require": {
        "edwinylil1/laravelshoppingcart": "^3.0"
    }
}
```

or execute

```bash
    composer require edwinylil1/laravelshoppingcart
```

When upgrading from 2.x, review [CHANGELOG.md](CHANGELOG.md) and the
[adjustments guide](docs/adjustments.md#upgrading-from-2x-to-3x): v3 changes
fiscal semantics and monetary precision.

## User guide

You can follow the links to quickly navigate to the topic of your interest:

* [Installation](#installation)
* [Configuration](#configuration)
* [Usage](#usage)
* [Instances](#instances)
* [Database](#database)
* [Collections](#collections)
* [Models](#models)
* [Exceptions](#exceptions)
* [Events](#events)

## Configuration

The shopping cart stores its active state in Laravel's configured session storage.
Database persistence is optional and is only required when you want to store a cart
and retrieve or merge it later using `store()`, `restore()` or `merge()`.
You can therefore use the cart, products, costs, discounts, observations and
`summary()` without creating the `shopping_cart` table.
The session storage mechanism is controlled by Laravel through `SESSION_DRIVER`
(for example `file`, `redis` or `database`). If Laravel uses
`SESSION_DRIVER=database`, Laravel may require its own session table; that table
is separate from the optional `shopping_cart` table used by this package.
When optional cart persistence is used, the package uses the configured database
connection and the `shopping_cart` table by default.
The package is designed to handle four tax rates for products. If the tax rate is passed as null, a default tax option is set for the products.
The default tax values are as follows:

```php
    'default_aliquot' => 0,
    'taxes' => [
        '0' => [
            'name' => 'GENERAL',
            'value' => 16.00
        ],
        '1' => [
            'name' => 'EXEMPT',
            'value' => 0.00
        ],
        '2' => [
            'name' => 'REDUCED',
            'value' => 8.00
        ],
        '3' => [
            'name' => 'LUXURY',
            'value' => 31.00
        ]
    ]
```

The Venezuelan fiscal catalog requires exactly keys **0, 1, 2 and 3**:
all four are mandatory and no additional keys are allowed.

The `name` and `value` fields may be customized. Each `name` must be a string,
nonempty after trimming, and unique ignoring case and surrounding whitespace.
Each `value` must be numeric, finite and nonnegative. A fiscal line (product or
ITEM cost) referencing an aliquot outside 0..3 raises `InvalidArgumentException`;
it is never silently ignored.

For Venezuela, the package supports invoice calculation for the fiscal providers 'The Factory HKA' and 'PNP Developments'

There are three drivers; HKA is the default. `cart.format.decimals` controls both
presentation and monetary/fiscal precision: an integer from **0 to 4**, default **2**.

All examples below use two decimals and 16% VAT.

| Driver | Product base | VAT calculation |
| --- | --- | --- |
| GENERAL | HALF_UP at configured precision | HALF_UP VAT for each final fiscal line, then sum by tax category |
| HKA | HALF_UP at configured precision | Sum bases by tax category, then HALF_UP VAT; reconcile original item taxes |
| PNP | Truncated for subtotal; retain raw quantity × price for VAT | Truncate VAT for each fiscal line, then sum; never tax the grouped base |

GENERAL: quantity 2 × price 10.23 gives base 20.46 and line VAT 3.2736 → **3.27**,
not rounded unit VAT 1.64 × 2 = 3.28. Two distinct lines of 0.03 produce VAT
0.00 + 0.00 under GENERAL, while HKA calculates `(0.03 + 0.03) × 16% → 0.01`.

`summary()['bases']` remains grouped for all drivers: identical bases can correctly
produce different `summary()['taxes']`. Tax names come from `cart.taxes`.

PNP: two lines of 0.04 each produce `truncate(0.04 × 16%) = 0.00`, so total VAT
is **0.00**, not grouped VAT 0.01. PNP does not truncate the input before computing
line VAT: quantity 3 × price 0.023 gives raw base 0.069 and VAT **0.01**, while
its displayed subtotal base is 0.06.

**`CartItem::tax` now means VAT for the entire original line.** This applies to
`toArray()`, `toJson()` and `json_encode(Cart::content())`. `taxTotal` formats that
same amount; `total` is the quantized line base plus line VAT. `price` stays unit
price; `unitTax` and `priceTax = price + unitTax` are separate unit concepts.

Do not multiply `item.tax` by quantity again.

HKA reconciles the provisional item taxes with grouped fiscal VAT, separately for
each tax category. Example: A, quantity 3 × 0.34, has base 1.02 and provisional
VAT 0.16; B, quantity 1 × 0.89, has provisional VAT 0.14. Grouped VAT is
`1.91 × 16% → 0.31`, so **A.tax = 0.17 and B.tax = 0.14**.

Items are ordered by largest provisional tax, largest quantized base, then
lexicographically smallest rowId. A positive difference goes entirely to the first
item; a negative difference is subtracted in order, stopping each item at zero. This is
recalculated from original products, independently of document adjustments.

`content()`, `get()`, `getById()` and `getByRowId()` keep the original objects and
supply derived fiscal context without persisting tax overrides. A standalone or
detached CartItem has only its provisional HKA tax; fetch it through Cart to get
reconciliation against the current collection.

In `summary()`, PRORATED and discounts already belong to final product bases.
PNP applies those allocated adjustments to the raw product base before truncating
line VAT. Each ITEM cost is a separate fiscal line: GENERAL rounds its VAT, PNP
truncates its VAT, and HKA includes its base in grouped VAT. Costs are registered
with HALF_UP at configured precision. Tips and legacy costs do not generate VAT.

Money uses integer minor units: 100 per currency unit at precision 2, 1000 at
precision 3. Historical `cents` fields and `Money::cents()` retain their names but
use the configured scale. `allocations` uses that same scale. Snapshots v3 and
session metadata record precision; legacy/v2 monetary integers are interpreted
at precision 2 and converted, so historical `cents = 123` remains **1.23**, not 0.123.

Reducing precision rounds each stored operation HALF_UP.

See the [adjustments and migration guide](docs/adjustments.md) for formulas,
precision conversion and HKA reconciliation limits. To change configuration, publish it:

```bash
    php artisan vendor:publish --provider="JeleDev\Shoppingcart\ShoppingcartServiceProvider" --tag="config"
```

## Usage

* [Cart::add()](#add)
* [Cart::update()](#update)
* [Cart::content()](#content)
* [Cart::get()](#get)
* [Cart::search()](#search)
* [Cart::total()](#total)
* [Cart::tax()](#tax)
* [Cart::subtotal()](#subtotal)
* [Cart::count()](#count)
* [Cart::addCost()](#addCost)
* [Cart::getCost()](#getCost)
* [Cart::remove()](#remove)
* [Cart::destroy()](#destroy)

Import the class for its usage:

```
    use Cart;
```

You can operate the shopping cart using the following methods:

### add

Use `Cart::add()` to incorporate a commercial line into the cart. It returns the
resulting `CartItem`. Arguments are product code/id, name, quantity, unit price
before VAT, tax category and options. Omitting the category uses
`cart.default_aliquot`; an explicit category must exist in `cart.taxes`
(default keys: 0, 1, 2 and 3).

**Line identity = product code/id + options + tax category.**

`name`, `price` and `qty` are not part of identity. Every option participates,
including `image`, `color`, `size`, `presentation` and `variant`; options are not
merely display metadata.

```php
Cart::add('P001', 'Hammer', 1, 10.00, 0, ['image' => '/img/hammer.jpg']);
$item = Cart::add('P001', 'Hammer', 2, 10.00, 0, ['image' => '/img/hammer.jpg']);
// One P001 line: qty = 3, options.image = /img/hammer.jpg
```

Calling `add()` again with the same complete identity reuses the line and adds
quantity. Different options or tax categories create different identities:

```php
Cart::add('P001', 'Hammer', 1, 10.00, 0, []);
// A separate line from P001 with ['image' => '/img/hammer.jpg'].
```

**Consideration:** a different price or name does not create another identity.

For the same code, options and category, `add()` accumulates quantity and replaces
the other attributes with those supplied in the new addition, including price
and name. To edit an existing line, use `update()`.

The array form requires `id`, `name`, `qty` and `price`; `aliquot` and `options`
are optional:

```php
Cart::add(['id' => 'P002', 'name' => 'Pliers', 'qty' => 1, 'price' => 12.00]);
```

### update

Use `Cart::update()` to modify an existing line: quantity from the + / − buttons,
name, price, tax category, options, or attributes supplied by a `Buyable`.
It accepts a `rowId` or a product code that matches exactly one line.

For the cart's + button, send the new quantity:

```php
$item = Cart::get($rowId);
Cart::update($rowId, $item->qty + 1);
// Alternative when P001 identifies exactly one line:
$item = Cart::get('P001');
Cart::update('P001', $item->qty + 1);
```

No new `add()` call or resubmission of name, price, tax category, options or image
is needed. `update()` starts from the existing line and preserves omitted attributes.

Independent example with an image:

```php
$item = Cart::add('P001', 'Hammer', 1, 10.00, 0, ['image' => '/img/hammer.jpg']);
Cart::update($item->rowId, 2);
// Only qty changes; id, name, price, aliquot and options.image are preserved.
```

This preserves the image and identity. Calling
`Cart::add('P001', 'Hammer', 1, 10.00, 0, [])` instead would create a different
identity: `[]` differs from `['image' => '/img/hammer.jpg']`.
Pass a partial array or an object implementing `Buyable` for other changes:

```php
$item = Cart::update($rowId, ['name' => 'New name', 'price' => 11.00]);
$item = Cart::update($item->rowId, $product); // $product implements Buyable
```

Updating options or the tax category can change `rowId`; keep the returned item's
identifier. A matching destination identity merges quantities. A quantity of zero
or less removes the line.

### content

To retrieve the contents of the cart, you will use the `content()` method. This method will return a collection of CartItems that you can iterate over and display the content to your customers

```php
    Cart::content();
```

This method will return the content of the current cart instance, if you want the content of another instance, chain the calls.

```php
    Cart::instance('wishlist')->content();
```

### get

`get()`, `update()` and `remove()` accept a `rowId` or product code/id. A code is
valid only if it identifies exactly one line:

```php
Cart::get('P001');
Cart::update('P001', 2);
Cart::remove('P001');
```

If P001 has red and blue variants, or multiple options/tax category combinations,
`Cart::get('P001')` throws `AmbiguousItemException`; so do `update()` and `remove()`
with that code. Use the specific line's `rowId` instead.

`rowId` unambiguously identifies a cart line. Keep it from the returned `CartItem`
or `content()`; do not calculate or predict it in your application.

```php
$item = Cart::get($rowId);
```

Lookup checks the exact `rowId` first, then the product code. A missing line throws
`InvalidRowIDException`. See the [identity guide](docs/adjustments.md#public-api)
for explicit `getByRowId()` and `getById()` lookup.

### search

To find an item in the cart, you can use the `search()` method.
If, for example, you want to find all items with the name "tube", you can use the following code:

```php
    $cart->search(function ($cartItem, $rowId) {
        return $cartItem->name === 'tube';
    });
```

### total

The `total()` method can be used to obtain the calculated total of all items in the cart, taking into account the price, quantity and configured driver. It also includes any additional costs.

```php
    Cart::total();
```

You can set the default number format in the config file.

**If you're not using the Facade, but use dependency injection in your (for instance) Controller, you can also simply get the total property `$cart->total`**

### tax

`tax()` returns VAT on final product and ITEM cost bases according to the driver,
including prorated costs and discounts. It excludes tips and legacy costs.

```php
    Cart::tax();
```

Here's an example of a response with the HKA driver:

```
    {
        "GENERAL": {
            "IVA": 16,
            "value": 0
        },
        "CUSTOM NAME": {
            "IVA": 0,
            "value": 0
        },
        "REDUCED": {
            "IVA": 8,
            "value": 0
        },
        "LUXURY": {
            "IVA": 31,
            "value": 2.01
        }
    }
```

Tax amounts are numeric; `cart.format.decimals` controls their precision. Separators only affect presentation.

**If you're not using the Facade, but use dependency injection in your (for instance) Controller, you can also simply get the tax property `$cart->tax`**

### subtotal

`subtotal()` returns the formatted final base of products and ITEM costs after
prorated costs and discounts. It excludes VAT, tips and legacy costs.

```php
    Cart::subtotal();
```

You can set the default number format in the config file.

**If you're not using the Facade, but use dependency injection in your (for instance) Controller, you can also simply get the subtotal property `$cart->subtotal`**

### count

If you want to know how many items are in your cart, you can use the `count()` method. This method will return the total quantity of items in the cart. So, if you added 2 tubes and 1 television, it will return 3 items.

```php
    Cart::count();
```

### addCost

If you want to add additional costs to the cart you can use the `addCost()` method. The method accepts a cost name and the price of the cost. This can be used for eg shipping or transaction costs.

```php
    Cart::addCost($name, $price)
```

Costs persist per cart instance, including database store/restore. The two-argument call retains the legacy surcharge without tax. Use `addCost('freight', 10, 'prorated')` or `addCost('installation', 20, 'item', 0)` for explicit treatment. `getCost()` stays formatted; `costDetails()` returns structured operations.

### getCost

Get an addition cost you added by `addCost()`. Accepts the cost name. Returns the formatted price of the cost.

```php
    Cart::getCost($name)
```

### remove

Remove a line using its `rowId` or a product code matching exactly one line:

```php
Cart::remove($rowId);
// Alternative for a unique product code:
Cart::remove('P001');
```

An ambiguous code throws `AmbiguousItemException`; use `rowId` for variants.

### destroy

If you want to completely remove the content of a cart, you can call the `destroy()` method on the cart. This will remove all CartItems from the cart for the current cart instance.

```php
Cart::destroy();
```

## Instances

Multiple instances of the cart are supported. Here's how it works:

You can set the current instance of the cart by calling:

```php
    Cart::instance('Instance name');
```

From this moment, the active instance of the cart will be `Instance name`, so when you add, remove or get the content of the cart, you're work with the `Instance name` instance of the cart.

If you want to switch instances, you just call Cart::instance('New instance') again, and you're working with the New instance again.

So a little example:

```php
    Cart::instance('shopping')->add('code', 'Product 1', 1, 9.99);
    // Get the content of the 'shopping' cart
    Cart::content();
    Cart::instance('wishlist')->add('code', 'Product 2', 1, 19.95, 1, ['image' => 'url image']);
    // Get the content of the 'wishlist' cart
    Cart::content();
    // If you want to get the content of the 'shopping' cart again
    Cart::instance('shopping')->content();
    // And the count of the 'wishlist' cart again
    Cart::instance('wishlist')->count();
```

**N.B. Keep in mind that the cart stays in the last set instance for as long as you don't set a different one during script execution.**

**N.B.2 The default cart instance is called `shopping_cart`, so when you're not using instances,`Cart::content();` is the same as `Cart::instance('shopping_cart')->content()`.**

## Database

> **Database persistence is optional.**
>
> The active cart and its adjustment metadata are stored in Laravel's session.
> You do not need the `shopping_cart` table to use the normal cart API,
> including products, costs, discounts, observations and `summary()`.
>
> The `shopping_cart` table is only required when using persisted-cart operations
> such as `store()`, `restore()` or `merge()`.

* [Config](#data-base-configuration)
* [Storing the cart](#storing-the-cart)
* [Restoring the cart](#restoring-the-cart)
* [Merging a stored cart](#merging-a-stored-cart)

| Feature | Laravel session | `shopping_cart` table |
| --- | --- | --- |
| Active cart | Required | Not required |
| Products | Yes | Not required |
| Costs and discounts | Yes | Not required |
| Observations | Yes | Not required |
| `summary()` / totals / taxes | Yes | Not required |
| `store()` | Yes | Required |
| `restore()` | Yes | Required |
| `merge()` persisted cart | Yes | Required |

### Data base Configuration

By default, the package uses the database connection configured through `DB_CONNECTION`,
falling back to `mysql`, and uses the `shopping_cart` table for optional cart persistence.

If you wish to change these options, you will need to publish the configuration file.

```bash
    php artisan vendor:publish --provider="JeleDev\Shoppingcart\ShoppingcartServiceProvider" --tag="config"
```

This will give you a `cart.php` config file in which you can make the changes.

If you want to use database persistence through `store()`, `restore()` or `merge()`,
the package includes a ready-to-use migration:

```bash
    php artisan vendor:publish --provider="JeleDev\Shoppingcart\ShoppingcartServiceProvider" --tag="migrations"
```

This publishes the migration for the `shopping_cart` table into the `database/migrations` directory.

Run the migration only when you want to enable this optional persistence feature:

```bash
php artisan migrate
```

If you only use the cart through Laravel's configured session storage,
publishing or running the `shopping_cart` migration is not required.

### Storing the cart

To store your cart instance into the database, you have to call the `store($identifier) ` method. Where `$identifier` is a random key, for instance the id or username of the user.

```php
    Cart::store('username');
```

To store a cart instance named 'custom name'

```php
    Cart::instance('custom name')->store('code');
```

### Restoring the cart

If you want to retrieve the cart from the database and restore it, all you have to do is call the `restore($identifier)` method, where `$identifier` is the key you specified for the `store()` method.

```php
Cart::restore('username');
```

To restore a cart instance named 'custom name':

```php
Cart::instance('custom name')->restore('code');
```

### Merging a stored cart

Use `merge($identifier)` to merge a previously stored cart into the currently
active cart.

```php
Cart::merge('username');
```

To merge a stored cart into a named cart instance:

```php
Cart::instance('custom name')->merge('code');
```

The stored cart is read from the optional `shopping_cart` persistence table and
merged into the active Laravel session cart.

Unlike `restore()`, `merge()` keeps the stored cart available for later use.

## Collections

On multiple instances the Cart will return to you a Collection. This is just a simple Laravel Collection, so all methods you can call on a Laravel Collection are also available on the result.

As an example, you can quicky get the number of unique products in a cart:

```php
    Cart::content()->count();
```

Or you can group the content by the id of the products:

```php
    Cart::content()->groupBy('id');
```

## Models

Because it can be very convenient to be able to directly access a model from a CartItem is it possible to associate a model with the items in the cart. Let's say you have a `Product` model in your application. With the `associate()` method, you can tell the cart that an item in the cart, is associated to the `Product` model.

That way you can access your model right from the `CartItem`!

The model can be accessed via the `model` property on the CartItem.

**If your model implements the `Buyable` interface and you used your model to add the item to the cart, it will associate automatically.**

Here is an example:

```php
    // First we'll add the item to the cart.
    $cartItem = Cart::add('code', 'Product name', 1, 9.99);
    // Next we associate a model with the item.
    Cart::associate($cartItem->rowId, 'Product');
    // Or even easier, call the associate method on the CartItem!
    $cartItem->associate('Product');
    // You can even make it a one-liner
    Cart::add('code', 'Product name', 1, 9.99)->associate('Product');
    // Now, when iterating over the content of the cart, you can access the model.
    foreach(Cart::content() as $row) {
        echo 'You have ' . $row->qty . ' items of ' . $row->model->name . ' with description: "' . $row->model->description . '" in your cart.';
    }
```

## Exceptions

The package will throw exceptions if something goes wrong. This makes it easier to debug your code when using the package or handle errors based on the type of exceptions. The following exceptions can be thrown:

| Exception                    | Reason                                                                             |
| ---------------------------- | ---------------------------------------------------------------------------------- |
| *CartAlreadyStoredException* | When trying to store a cart that was already stored using the specified identifier |
| *InvalidRowIDException*      | When the rowId that got passed doesn't exists in the current cart instance         |
| *UnknownModelException*      | When you try to associate an none existing model to a CartItem.                    |
| *AmbiguousItemException*     | When a product code matches multiple cart lines; use the specific `rowId`          |

## Events

The cart also has events build in. There are five events available for you to listen for:

| Event         | Fired                                    | Parameter                        |
| ------------- | ---------------------------------------- | -------------------------------- |
| cart.added    | When an item was added to the cart.      | The `CartItem` that was added.   |
| cart.updated  | When an item in the cart was updated.    | The `CartItem` that was updated. |
| cart.removed  | When an item is removed from the cart.   | The `CartItem` that was removed. |
| cart.stored   | When the content of a cart was stored.   | -                                |
| cart.restored | When the content of a cart was restored. | -                                |
| cart.adding   | Before a `CartItem` is added through `addCartItem()`. | The `CartItem` being added. |
| cart.merged   | When a persisted cart is merged into the active cart. | - |

## Contributing

Contributing is easy! Just fork the repo, make your changes then send a pull request on GitHub. If your PR is languishing in the queue and nothing seems to be happening, then send EVillegas an [email](mailto:devvillegas@proton.me).

## Donations

#### by paypal: from devvillegas@proton.me

#### by Binance pay: 359233003