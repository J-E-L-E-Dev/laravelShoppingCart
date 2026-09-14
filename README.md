# Laravel Shopping Cart

The cart now supports persistent ITEM/PRORATED costs, tips, line/document discounts,
structured observations and safe lookup by product code. See the
[adjustments and migration guide (Spanish)](docs/adjustments.es.md) for the complete
API, calculation order, rounding rules and compatibility changes. Use `summary()`
for adjusted invoice bases; `content()` retains original product attributes.

### Compatibility:
[![Laravel 7.x](https://img.shields.io/badge/Laravel-7.x-red.svg)](https://laravel.com/docs/7.x)
[![Laravel 8.x](https://img.shields.io/badge/Laravel-8.x-red.svg)](https://laravel.com/docs/8.x)
[![Laravel 9.x](https://img.shields.io/badge/Laravel-9.x-red.svg)](https://laravel.com/docs/9.x)
[![Laravel 10.x](https://img.shields.io/badge/Laravel-10.x-red.svg)](https://laravel.com/docs/10.x)
[![Laravel 11.x](https://img.shields.io/badge/Laravel-11.x-red.svg)](https://laravel.com/docs/11.x)

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
    "require": {
        "edwinylil1/laravelshoppingcart": "~1.0.0",
    },
```

or execute

```bash
    composer require edwinylil1/laravelshoppingcart
```

### Laravel <= 7.0
If you still have Laravel version 7.0, you need to add the package's service provider and assign it an alias. To do this, open your config/app.php file

```bash
nano config/app.php
```

### Add a new line to the providers array:

```bash
JeleDev\Shoppingcart\ShoppingcartServiceProvider::class
```

And add a new line to the `aliases` array:

```bash
'Cart' => JeleDev\Shoppingcart\Facades\Cart::class,
```

Now you're ready to start using the shopping cart in your application.

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

The shopping cart stores information in sessions. However, you can save the cart in the database to retrieve it later.

By default, the package will use the 'MySQL' database connection and utilize a table named 'shopping_cart'.

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

You can modify the name and value properties to your needs.

For Venezuela, the package supports invoice calculation for the fiscal providers 'The Factory HKA' and 'PNP Developments'

There are three drivers; HKA is the default. `config('cart.driver')` controls both
how bases and VAT are quantized and when bases are accumulated before calculating VAT.

| Driver | Base per product line | VAT calculation |
| --- | --- | --- |
| GENERAL | HALF_UP to 2 decimal places | Round VAT for each final fiscal line, then sum by tax category |
| HKA | HALF_UP to 2 decimal places | Sum final bases by tax category, then round VAT |
| PNP | Truncate toward zero to 2 decimal places | Sum truncated bases by tax category, then truncate VAT |

First quantize `quantity × price`, then add allocated PRORATED costs and subtract
applicable line and document discounts to obtain each final product base.
Allocations and discounts operate in cents. Each ITEM cost is another fiscal line:
GENERAL taxes it independently; HKA and PNP include it in its tax category's
accumulated base. ITEM amounts are already rounded to cents when registered.
PRORATED is already included in product bases and is not added again. Tips and
legacy costs increase the total without entering taxable bases or VAT.

**GENERAL versus HKA, two separate lines of 0.03 at 16% VAT:**

```text
GENERAL: 0.03 × 16% = 0.0048 → 0.00 for each line; VAT = 0.00
HKA:     (0.03 + 0.03) × 16% = 0.0096 → 0.01

GENERAL base = 0.06; VAT = 0.00
HKA base     = 0.06; VAT = 0.01
```

`summary()['bases']` always contains final bases grouped by tax category for
invoicing and queries. This does not mean GENERAL uses grouped bases to calculate
VAT. **Identical bases with different `summary()['taxes']` are correct and expected.**
Tax entries use names from `config('cart.taxes')`; `IVA` is the percentage and
`value` is the tax amount.

**GENERAL uses the whole line, including quantity:** for quantity 2, price 10.23
and VAT 16%, the base is `2 × 10.23 = 20.46`; VAT is `3.2736 → 3.27`.
Rounding unit VAT first would give `1.64 × 2 = 3.28`, which is not this strategy.

**HKA versus PNP, two separate lines of 0.039 at 16% VAT:**

```text
HKA: 0.039 → 0.04 each; base = 0.08; VAT = 0.0128 → 0.01
PNP: 0.039 → 0.03 each; base = 0.06; VAT = 0.0096 → 0.00
```

See the [adjustments guide (Spanish)](docs/adjustments.es.md#orden-matemático)
for the complete calculation order. To change the driver, publish the configuration file.

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
`InvalidRowIDException`. See the [identity guide](docs/adjustments.es.md#api-pública)
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

Tax amounts are numeric; `cart.format` does not change them.

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

* [Config](#data-base-configuration)
* [Storing the cart](#save-cart-to-database)
* [Restoring the cart](#retrieve-cart-from-database)

### Data base Configuration

By default, the package will use the 'MySQL' database connection and utilize a table named 'shopping_cart'.
If you wish to change these options, you will need to publish the configuration file.

```bash
    php artisan vendor:publish --provider="JeleDev\Shoppingcart\ShoppingcartServiceProvider" --tag="config"
```

This will give you a `cart.php` config file in which you can make the changes.

To make your life easy, the package also includes a ready to use `migration` which you can publish by running:

```bash
    php artisan vendor:publish --provider="JeleDev\Shoppingcart\ShoppingcartServiceProvider" --tag="migrations"
```

This will place a `shopping_cart` table's migration file into `database/migrations` directory. Now all you have to do is run `php artisan migrate` to migrate your database.

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
If you want to retrieve the cart from the database and restore it, all you have to do is call the  `restore($identifier)` where `$identifier` is the key you specified for the `store` method.

```php
    Cart::restore('username');
```

To restore a cart instance named 'custom name'

```php
    Cart::instance('custom name')->restore('code');
```

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

| Exception                    | Reason                                                                             |
| ---------------------------- | ---------------------------------------------------------------------------------- |
| *CartAlreadyStoredException* | When trying to store a cart that was already stored using the specified identifier |
| *InvalidRowIDException*      | When the rowId that got passed doesn't exists in the current cart instance         |
| *UnknownModelException*      | When you try to associate an none existing model to a CartItem.                    |

## Events

The cart also has events build in. There are five events available for you to listen for:

| Event         | Fired                                    | Parameter                        |
| ------------- | ---------------------------------------- | -------------------------------- |
| cart.added    | When an item was added to the cart.      | The `CartItem` that was added.   |
| cart.updated  | When an item in the cart was updated.    | The `CartItem` that was updated. |
| cart.removed  | When an item is removed from the cart.   | The `CartItem` that was removed. |
| cart.stored   | When the content of a cart was stored.   | -                                |
| cart.restored | When the content of a cart was restored. | -                                |

## Contributing

Contributing is easy! Just fork the repo, make your changes then send a pull request on GitHub. If your PR is languishing in the queue and nothing seems to be happening, then send EVillegas an [email](mailto:devvillegas@proton.me).

## Donations
#### by paypal: from devvillegas@proton.me
#### by Binance pay: 359233003
