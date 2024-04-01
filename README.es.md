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

## Gu&iacute;a de uso:
[![en](https://img.shields.io/badge/lang-en-red.svg)](https://github.com/J-E-L-E-Dev/laravelShoppingCart)
[![es](https://img.shields.io/badge/lang-es-yellow.svg)](https://github.com/J-E-L-E-Dev/laravelShoppingCart/blob/main/README.es.md)

Laravel Shopping Cart es un paquete de carrito de compras que permite manejar diferentes tasas de impuestos para los productos.

## Instalación

Podemos agregar la dependencia en nuestro archivo `composer.json`:

```json
    "require": {
        "edwinylil1/laravelshoppingcart": "~1.0.0",
    },
```

o ejecutar

```bash
    composer require edwinylil1/laravelshoppingcart
```

### Laravel <= 7.0

Si usa la versión 7.0 de Laravel, debe agregar el service provider del paquete y asignarle un alias. Para hacer esto, abra su archivo config/app.php


```bash
nano config/app.php
```

### Agregue una nueva línea a la matriz providers:

```bash
JeleDev\Shoppingcart\ShoppingcartServiceProvider::class
```

Y agregue una nueva l&iacute;nea a la matriz `aliases`:

```bash
'Cart' => JeleDev\Shoppingcart\Facades\Cart::class,
```

Ahora est&aacute;s listo para comenzar a usar el carrito de compras en tu aplicaci&oacute;n.

## Gu&iacute;a del usuario

Puede seguir los enlaces para navegar r&aacute;pidamente al tema de su inter&eacute;s.:

* [Instalación](#instalación)
* [Configuración](#configuración)
* [Uso](#uso)
* [Instancias](#instancias)
* [Base de datos](#base-de-datos)
* [Colecciones](#colecciones)
* [Modelos](#modelos)
* [Excepciones](#excepciones)
* [Eventos](#eventos)

## Configuración

El carrito de compras almacena informaci&oacute;n en sesiones. Sin embargo, puede guardar el carrito en la base de datos para recuperarlo m&aacute;s tarde.

De forma predeterminada, el paquete utilizar&aacute; la conexi&oacute;n de base de datos 'MySQL' y utilizar&aacute; una tabla llamada 'shopping_cart'.

El paquete est&aacute; diseñado para manejar cuatro tasas de impuestos para productos. Si la alicuota se pasa en null, se establece una alicuota predeterminada para los productos.


Las alicuotas con sus valores de tasas por defecto son:

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

Puede modificar las propiedades name y value seg&uacute;n sus necesidades.

Para Venezuela, el paquete soporta el c&aacute;lculo de facturas para los proveedores fiscales 'The Factory HKA' y 'Desarrollos PNP'

Hay tres opciones de controlador disponibles, siendo 'HKA' la opci&oacute;n predeterminada. Las diferencias en el c&aacute;lculo de precios entre ellos son las siguientes:

```
    GENERAL:
           55.866 = 55.87 | 55.865 = 55.87 | 55.864 = 55.86
        
    HKA:
           55.866 = 55.87 | 55.865 = 55.87 | 55.864 = 55.86
       
    PNP:
           55.866 = 55.86 | 55.865 = 55.86 | 55.864 = 55.86
```
Si desea cambiar estas opciones, deber&aacute; publicar el archivo de configuraci&oacute;n

```bash
    php artisan vendor:publish --provider="JeleDev\Shoppingcart\ShoppingcartServiceProvider" --tag="config"
```

## Uso

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

Importar la clase para su uso.:

```
    use Cart;
```

Puede operar el carrito de compras utilizando los siguientes m&eacute;todos:

### add

Para agregar un art&iacute;culo al carrito de compras, simplemente use el m&eacute;todo `add()`, que acepta una variedad de par&aacute;metros.

En su forma m&aacute;s b&aacute;sica, puede especificar el id, nombre, la cantidad y el precio del producto que desea agregar al carrito.

```
   Cart::add('code','product name...',1,1.30);
```

De esta manera, se agregar&aacute; el art&iacute;culo y el monto del impuesto se calcular&aacute; utilizando la alicuota predeterminada definida por la propiedad default_aliquot en el archivo de configuraci&oacute;n.

Opcionalmente, el quinto par&aacute;metro corresponde al identificador de la alicuota a aplicar, que toma valores de 0 a 4. Mientras tanto, el sexto par&aacute;metro es un conjunto de opciones que puedes utilizar seg&uacute;n tus necesidades, como proporcionar una URL de imagen para exhibir el producto.

```
    Cart::add('code','product name...',1,1.30,1,["image" => 'url image']);
```

**El m&eacute;todo `add()` devolver&aacute; una instancia de CartItem del art&iacute;culo que acaba de agregar al carrito.**

Si prefiere agregar un elemento usando una matriz, siempre que la matriz contenga las claves requeridas, puede pasarlo al m&eacute;todo omitiendo el resto de los par&aacute;metros. Las claves de alícuota y opciones son opcionales.

```
    Cart::add(['id' => 'code', 'name' => 'product name...', 'qty' => 1, 'price' => 1.30, 'options' => ["image" => 'url image']]);
```

¿Qu&eacute; pasa si el mismo art&iacute;culo se env&iacute;a dos veces al carrito? En estos casos, se implementa una interfaz Comparable. Como resultado, en lugar de agregar dos art&iacute;culos separados al carrito, buscar&aacute; el art&iacute;culo existente e incrementar&aacute; su cantidad en la cantidad proporcionada.

### update

Para actualizar un art&iacute;culo en el carrito, necesitar&aacute; el rowId. Puede utilizar el m&eacute;todo `update()` para actualizarlo. Si solo desea actualizar la cantidad, pasar&aacute; el rowId y la nueva cantidad al m&eacute;todo de actualizaci&oacute;n.



```php
    $rowId = '26d21fe340e373e8e7e20f87e0860b74';
    Cart::update($rowId, 2);
```

Si desea actualizar m&aacute;s atributos del elemento, puede pasar una matriz o un Comparable como segundo par&aacute;metro al m&eacute;todo de actualizaci&oacute;n. De esta manera, puede actualizar toda la informaci&oacute;n del art&iacute;culo con el rowId proporcionado.

```php
    $rowId = '26d21fe340e373e8e7e20f87e0860b74';   
    Cart::update($rowId, ['name' => 'new name']);
    Cart::update($rowId, $product);
```

### content

Para recuperar el contenido del carrito, utilizar&aacute; el m&eacute;todo `content()`. Este m&eacute;todo devolver&aacute; una colecci&oacute;n de CartItems que puede iterar y mostrar el contenido a sus clientes.


```php
    Cart::content();
```

Este m&eacute;todo devolver&aacute; el contenido de la instancia del carrito actual, si desea el contenido de otra instancia, encadene las llamadas.

```php
    Cart::instance('wishlist')->content();
```

### get

Si desea recuperar solo un art&iacute;culo del carrito, puede llamar al m&eacute;todo `get()` y pasarle el rowId.

```php
    $rowId = '26d21fe340e373e8e7e20f87e0860b74';   
    Cart::get($rowId);
```

### search

Para buscar un art&iacute;culo en el carrito, puede utilizar el m&eacute;todo `search()`.

Si, por ejemplo, desea buscar todos los elementos con el nombre "tubo", puede utilizar el siguiente c&oacute;digo:

```php
    $cart->search(function ($cartItem, $rowId) {
        return $cartItem->name === 'tubo';
    });
```

### total

El m&eacute;todo `total()` se puede utilizar para obtener el total calculado de todos los art&iacute;culos del carrito, teniendo en cuenta el precio, la cantidad y el controlador configurado. Tambi&eacute;n incluye cualquier coste adicional.

```php
    Cart::total();
```

Puede configurar el formato de n&uacute;mero predeterminado en el archivo de configuraci&oacute;n.

**Si no est&aacute;s usando Fachada, pero usas la inyecci&oacute;n de dependencia en tu (por ejemplo) Controlador, tambi&eacute;n puedes simplemente obtener la propiedad total `$cart->total`**

### tax

El m&eacute;todo `tax()` se puede utilizar para obtener el importe calculado del impuesto para todos los art&iacute;culos del carrito, teniendo en cuenta el precio, la cantidad y el controlador configurado.

```php
    Cart::tax();
```

A continuaci&oacute;n se muestra un ejemplo de una respuesta con el controlador HKA:

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

Puede configurar el formato de n&uacute;mero predeterminado en el archivo de configuraci&oacute;n.

**Si no est&aacute;s usando Facade, pero usas la inyecci&oacute;n de dependencia en tu (por ejemplo) Controller, tambi&eacute;n puedes simplemente obtener la propiedad de impuesto `$cart->tax`**

### subtotal

El m&eacute;todo `subtotal()` se puede utilizar para obtener el total de todos los art&iacute;culos del carrito, menos el importe total del impuesto.

```php
    Cart::subtotal();
```

Puede configurar el formato de n&uacute;mero predeterminado en el archivo de configuraci&oacute;n.

**Si no est&aacute;s usando Facade, pero usas la inyecci&oacute;n de dependencia en tu (por ejemplo) Controller, tambi&eacute;n puedes simplemente obtener la propiedad de impuesto `$cart->subtotal`**

### count

Si desea saber cu&aacute;ntos art&iacute;culos hay en su carrito, puede utilizar el m&eacute;todo `count()`. Este m&eacute;todo devolver&aacute; la cantidad total de art&iacute;culos en el carrito. Entonces, si agregaste 2 tubos y 1 televisor, te devolver&aacute; 3 art&iacute;culos.

```php
    Cart::count();
```

### addCost

Si desea agregar costos adicionales al carrito, puede utilizar el m&eacute;todo `addCost()`. El m&eacute;todo acepta un nombre de costo y el precio del costo. Esto se puede utilizar, por ejemplo, para costos de envío o de transacci&oacute;n.

```php
    Cart::addCost($name, $price)
```

**Agregue este m&eacute;todo antes de resumir todo el carrito. Los costos no se guardan en la sesi&oacute;n (a&uacute;n).**

### getCost

Obtenga un costo adicional que agreg&oacute; mediante `addCost()`. Acepta el nombre del costo. Devuelve el precio formateado del costo.

```php
    Cart::getCost($name)
```

### remove

Para eliminar un art&iacute;culo del carrito, debe pasar el rowId al m&eacute;todo `remove()` y este eliminar&aacute; el art&iacute;culo del carrito.

```php
    $rowId = '26d21fe340e373e8e7e20f87e0860b74';   
    Cart::remove($rowId);
```

### destroy

Si desea eliminar completamente el contenido de un carrito, puede llamar al m&eacute;todo `destroy()` en el carrito. Esto eliminar&aacute; todos los CartItems del carrito para la instancia de carrito actual.

```php
Cart::destroy();
```

## Instancias

Se admiten varias instancias del carrito. As&iacute; es como funciona:

Puede configurar la instancia actual del carrito llamando:

```php
    Cart::instance('Instance name');
```

A partir de este momento, la instancia activa del carrito ser&aacute; `Instance name`, por lo que cuando agregas, eliminas u obtienes el contenido del carrito, est&aacute;s trabajando con la instancia `Instance name` del carrito.

Si desea cambiar de instancia, simplemente llame a Cart::instance('Instance name') nuevamente y estar&aacute; trabajando con la Nueva instancia nuevamente.

Entonces un pequeño ejemplo:

```php
    Cart::instance('shopping')->add('code', 'Product 1', 1, 9.99);

    // Obtener el contenido del carrito de 'shopping'
    Cart::content();

    Cart::instance('wishlist')->add('code', 'Product 2', 1, 19.95, 1, ['image' => 'url image']);

    // Obtener el contenido del carrito 'wishlist'
    Cart::content();

    // Si deseas volver a obtener el contenido del carrito de 'shopping'
    Cart::instance('shopping')->content();

    // Y otra vez el recuento del carrito 'wishlist'
    Cart::instance('wishlist')->count();
```
**NOTA. Tenga en cuenta que el carrito permanece en la &uacute;ltima instancia establecida mientras no establezca una diferente durante la ejecuci&oacute;n del script.**

**NOTA.2 La instancia de carrito predeterminada se llama `shopping_cart`, por lo que cuando no est&aacute;s usando instancias, `Cart::content();` es lo mismo que `Cart::instance('shopping_cart')->content()`.**

## Base de datos

* [Configuración](#configuración-de-base-de-datos)
* [Guardar el carrito](#guardar-el-carrito)
* [Restaurando el carro](#restaurando-el-carro)

### Configuración de base de datos

De forma predeterminada, el paquete utilizar&aacute; la conexi&oacute;n de base de datos 'MySQL' y utilizar&aacute; una tabla llamada 'shopping_cart'.
Si desea cambiar estas opciones, deber&aacute; publicar el archivo de configuraci&oacute;n.

```bash
    php artisan vendor:publish --provider="JeleDev\Shoppingcart\ShoppingcartServiceProvider" --tag="config"
```

Esto le dar&aacute; un archivo de configuraci&oacute;n `cart.php` en el que podr&aacute; realizar los cambios.

Para facilitarle la vida, el paquete tambi&eacute;n incluye una "migraci&oacute;n" lista para usar que puede publicar ejecutando:

```bash
    php artisan vendor:publish --provider="JeleDev\Shoppingcart\ShoppingcartServiceProvider" --tag="migrations"
```

Esto colocar&aacute; el archivo de migraci&oacute;n de la tabla `shopping_cart` en el directorio `database/migrations`. Ahora todo lo que tienes que hacer es ejecutar `php artisan migrate` para migrar tu base de datos.

### Guardar el carrito

Para almacenar su instancia de carrito en la base de datos, debe llamar al m&eacute;todo `store($identifier) ​​`. Donde `$identifier` es una clave aleatoria, por ejemplo, la identificaci&oacute;n o el username del usuario.

```php
    Cart::store('username');
```

Para almacenar una instancia de carrito llamada 'custom name'

```php
    Cart::instance('custom name')->store('code');
```

### Restaurando el carro

Si desea recuperar el carrito de la base de datos y restaurarlo, todo lo que tiene que hacer es llamar a `restore($identifier)` donde `$identifier` es la clave que especific&oacute; para el m&eacute;todo `store`.

```php
    Cart::restore('username');
```

Para restaurar una instancia de carrito llamada 'custom name'

```php
    Cart::instance('custom name')->restore('code');
```

## Colecciones

En m&uacute;ltiples casos, el carrito le devolver&aacute; una colecci&oacute;n. Esta es solo una colecci&oacute;n simple de Laravel, por lo que todos los m&eacute;todos que puede invocar en una colecci&oacute;n Laravel tambi&eacute;n est&aacute;n disponibles en el resultado.

Como ejemplo, puede obtener r&aacute;pidamente la cantidad de productos &uacute;nicos en un carrito:

```php
    Cart::content()->count();
```

O puedes agrupar el contenido por el id de los productos:

```php
    Cart::content()->groupBy('id');
```

## Modelos

Debido a que puede ser muy conveniente poder acceder directamente a un modelo desde un CartItem, es posible asociar un modelo con los art&iacute;culos del carrito. Digamos que tiene un modelo de "Product" en su aplicaci&oacute;n. Con el m&eacute;todo `associate()`, puede decirle al carrito que un art&iacute;culo en el carrito est&aacute; asociado al modelo `Product`.

¡De esa manera podr&aacute;s acceder a tu modelo directamente desde `CartItem`!

Se puede acceder al modelo a trav&eacute;s de la propiedad "model" en CartItem.

**Si su modelo implementa la interfaz "Buyable" y utilizó su modelo para agregar el art&iacute;culo al carrito, se asociar&aacute; autom&aacute;ticamente.**

Aqu&iacute; hay un ejemplo:

```php
    // Primero agregaremos el artículo al carrito.
    $cartItem = Cart::add('code', 'Product name', 1, 9.99);

    // A continuación asociamos un modelo con el artículo.
    Cart::associate($cartItem->rowId, 'Product');

    // O incluso más fácil, ¡llame al método asociado en CartItem!
    $cartItem->associate('Product');

    // Incluso puedes convertirlo en una sola línea.
    Cart::add('code', 'Product name', 1, 9.99)->associate('Product');

    // Ahora, al iterar sobre el contenido del carrito, podrás acceder al modelo.
    foreach(Cart::content() as $row) {
        echo 'Tienes ' . $row->qty . ' items de ' . $row->model->name . ' con la descripción: "' . $row->model->description . '" en el carrito.';
    }
```

## Excepciones

El paquete generar&aacute; excepciones si algo sale mal. Esto hace que sea m&aacute;s f&aacute;cil depurar su c&oacute;digo cuando usa el paquete o manejar errores seg&uacute;n el tipo de excepciones. Se pueden producir las siguientes excepciones:

| Excepci&oacute;n             | Raz&oacute;n                                                                                   |
| ---------------------------- | ---------------------------------------------------------------------------------------------- |
| *CartAlreadyStoredException* | Al intentar almacenar un carrito que ya estaba almacenado usando el identificador especificado |
| *InvalidRowIDException*      | Cuando el rowId que se pas&oacute; no existe en la instancia del carrito actual                |
| *UnknownModelException*      | Cuando intentas asociar un modelo que no existe a un CartItem                                  |

## Eventos

El carrito tambi&eacute;n tiene eventos integrados. Hay cinco eventos disponibles para que los escuches:

| Evento        | Disparador                                                   | Par&aacute;metro                    |
| ------------- | ------------------------------------------------------------ | ----------------------------------- |
| cart.added    | Cuando se agreg&oacute; un art&iacute;culo al carrito.       | El `CartItem` que se agreg&oacute;. |
| cart.updated  | Cuando se actualiz&oacute; un art&iacute;culo en el carrito. | El `CartItem` que se actualizo.     |
| cart.removed  | Cuando se elimina un art&iacute;culo del carrito.            | El `CartItem` que se removio.       |
| cart.stored   | Cuando se almacen&oacute; el contenido de un carrito.        | -                                   |
| cart.restored | Cuando se restaur&oacute; el contenido de un carrito.        | -                                   |

## Contribuir

¡Contribuir es f&aacute;cil! Simplemente bifurque el repositorio, realice los cambios y luego env&iacute;e una solicitud de extracci&oacute;n en GitHub. Si su RP languidece en la cola y parece que no sucede nada, env&iacute;e a EVillegas un [email](mailto:devvillegas@proton.me).

## Donaciones
#### Por paypal: para devvillegas@proton.me
#### Por Binance pay: 359233003