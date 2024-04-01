# Laravel Shopping Cart
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

There are three available controller options, with 'HKA' being the default choice. The pricing calculation differences between them are as follows:

```
    GENERAL:
           55.866 = 55.87 | 55.865 = 55.87 | 55.864 = 55.86
        
    HKA:
           55.866 = 55.87 | 55.865 = 55.87 | 55.864 = 55.86
       
    PNP:
           55.866 = 55.86 | 55.865 = 55.86 | 55.864 = 55.86
```
If you wish to change these options, you will need to publish the configuration file.

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

To add an item to the shopping cart, simply use the `add()` method, which accepts a variety of parameters.

In its most basic form you can specify the id, name, quantity, price of the product you'd like to add to the cart.

```
   Cart::add('code','product name...',1,1.30);
```

In this way, the item will be added and the tax amount will be calculated using the default tax rate defined by the default_aliquot property in the configuration file.

Optionally, the fifth parameter corresponds to the identifier of the tax rate to apply, which takes values from 0 to 4. Meanwhile, the sixth parameter is an array of options that you can use according to your needs, such as providing an image URL to display the product.

```
    Cart::add('code','product name...',1,1.30,1,["image" => 'url image']);
```

**The `add()` method will return an CartItem instance of the item you just added to the cart.**

If you prefer to add an item using an array, as long as the array contains the required keys, you can pass it to the method while omitting the rest of the parameters. The aliquot and options keys are optional.

```
    Cart::add(['id' => 'code', 'name' => 'product name...', 'qty' => 1, 'price' => 1.30, 'options' => ["image" => 'url image']]);
```

What happens if the same item is sent to the cart twice? In these cases, a Comparable interface is implemented. As a result, instead of adding two separate items to the cart, it will search for the existing item and increment its quantity by the provided amount.

### update

To update an item in the cart, you will need the rowId. You can use the `update()` method to update it. If you only want to update the quantity, you will pass the rowId and the new quantity to the update method.



```php
    $rowId = '26d21fe340e373e8e7e20f87e0860b74';
    Cart::update($rowId, 2);
```
If you want to update more attributes of the item, you can pass an array or a Comparable as the second parameter to the update method. This way, you can update all the information of the item with the provided rowId.

```php
    $rowId = '26d21fe340e373e8e7e20f87e0860b74';   
    Cart::update($rowId, ['name' => 'new name']);
    Cart::update($rowId, $product);
```

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

If you want to retrieve only one item from the cart, you can call the `get()` method and pass the rowId to it.

```php
    $rowId = '26d21fe340e373e8e7e20f87e0860b74';   
    Cart::get($rowId);
```

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

The `tax()` method can be used to get the calculated amount of tax for all items in the cart, given there price, quantity and configured driver.

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

You can set the default number format in the config file.

**If you're not using the Facade, but use dependency injection in your (for instance) Controller, you can also simply get the tax property `$cart->tax`**

### subtotal

The `subtotal()` method can be used to get the total of all items in the cart, minus the total amount of tax.

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

**Add this method before summarizing the whole cart. The costs are not saved in the session (yet).**

### getCost

Get an addition cost you added by `addCost()`. Accepts the cost name. Returns the formatted price of the cost.

```php
    Cart::getCost($name)
```

### remove

To remove an item from the cart, you need to pass the rowId to the `remove()` method, and it will delete the item from the cart.

```php
    $rowId = '26d21fe340e373e8e7e20f87e0860b74';   
    Cart::remove($rowId);
```

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