# Laravel Shopping Cart

El carrito admite costos ITEM/PRORATED persistentes, propinas, descuentos por línea
y documento, observaciones estructuradas y búsqueda segura por código. Consultar
la [guía de ajustes y migración](docs/adjustments.es.md) para la API completa,
orden matemático, precisión y cambios de compatibilidad. Usar `summary()` para
las bases ajustadas de la factura; `content()` conserva los atributos originales.

### Compatibility:
[![Laravel 10.x](https://img.shields.io/badge/Laravel-10.x-red.svg)](https://laravel.com/docs/10.x)
[![Laravel 11.x](https://img.shields.io/badge/Laravel-11.x-red.svg)](https://laravel.com/docs/11.x)
[![Laravel 12.x](https://img.shields.io/badge/Laravel-12.x-red.svg)](https://laravel.com/docs/12.x)

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
        "edwinylil1/laravelshoppingcart": "~2.0.0",
    },
```

o ejecutar

```bash
    composer require edwinylil1/laravelshoppingcart
```

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

Hay tres drivers; HKA es el predeterminado. `cart.format.decimals` controla tanto
presentación como precisión monetaria/fiscal: entero entre **0 y 4**, por defecto **2**.
Los ejemplos siguientes usan dos decimales e IVA del 16%.

| Driver | Base del producto | Cálculo del IVA |
| --- | --- | --- |
| GENERAL | HALF_UP a la precisión configurada | HALF_UP del IVA por línea fiscal final; suma por alícuota |
| HKA | HALF_UP a la precisión configurada | Agrupa bases por alícuota y redondea IVA HALF_UP; reconcilia impuestos individuales |
| PNP | Truncada para subtotal; conserva cantidad × precio sin cuantizar para IVA | Trunca IVA por línea fiscal y luego suma; nunca calcula IVA sobre base agrupada |

GENERAL: cantidad 2 × precio 10.23 da base 20.46 e IVA de fila 3.2736 → **3.27**,
no IVA unitario redondeado 1.64 × 2 = 3.28. Dos líneas distintas de 0.03 producen
IVA 0.00 + 0.00 en GENERAL; HKA calcula `(0.03 + 0.03) × 16% → 0.01`.
`summary()['bases']` sigue agrupado para todos los drivers: bases iguales pueden
producir distintos `summary()['taxes']`. Los nombres de impuestos vienen de `cart.taxes`.

PNP: dos líneas de 0.04 producen cada una `truncar(0.04 × 16%) = 0.00`; el IVA
total es **0.00**, no el IVA agrupado 0.01. PNP no trunca la entrada antes de
calcular IVA: cantidad 3 × precio 0.023 da base sin cuantizar 0.069 e IVA **0.01**,
aunque la base presentada en subtotal es 0.06.

**`CartItem::tax` ahora representa el IVA de la fila original completa.** Aplica a
`toArray()`, `toJson()` y `json_encode(Cart::content())`. `taxTotal` formatea ese
mismo importe; `total` es base de fila cuantizada más IVA de fila. `price` sigue
siendo unitario; `unitTax` y `priceTax = price + unitTax` son conceptos unitarios
separados. No volver a multiplicar `item.tax` por cantidad.

HKA reconcilia los IVA provisionales con el IVA fiscal agrupado, separadamente
por alícuota. Ejemplo: A, cantidad 3 × 0.34, tiene base 1.02 e IVA provisional
0.16; B, cantidad 1 × 0.89, tiene IVA provisional 0.14. El IVA agrupado es
`1.91 × 16% → 0.31`, por lo que **A.tax = 0.17 y B.tax = 0.14**.
Se ordenan las filas por mayor IVA provisional, mayor base cuantizada y menor
rowId lexicográfico. La diferencia positiva se suma a la primera; la negativa
se resta en ese orden, sin bajar ninguna fila de cero. Se recalcula
sobre productos originales, independientemente de los ajustes documentales.

`content()`, `get()`, `getById()` y `getByRowId()` conservan los objetos originales
y proveen contexto fiscal derivado sin persistir impuestos sobrescritos. Un
CartItem independiente o separado de la colección sólo tiene su IVA HKA provisional;
consultarlo mediante Cart para reconciliarlo con la colección vigente.

En `summary()`, PRORATED y descuentos ya integran las bases finales de productos.
PNP aplica esos ajustes asignados a la base sin cuantizar antes de truncar el IVA
por fila. Cada ITEM es otra línea fiscal: GENERAL redondea su IVA, PNP lo trunca
y HKA incorpora su base al cálculo agrupado. Los costos se registran con HALF_UP
a la precisión configurada. Propina y legacy no generan IVA.

Money utiliza unidades menores enteras: 100 por unidad monetaria con precisión 2,
1000 con precisión 3. Los campos históricos `cents` y `Money::cents()` conservan
el nombre pero usan la escala configurada. `allocations` usa la misma escala.
Snapshots v3 y metadatos de sesión guardan la precisión; los enteros legacy/v2 se
interpretan con precisión 2 y se convierten: `cents = 123` histórico sigue siendo
**1.23**, no 0.123. Reducir precisión redondea cada operación almacenada HALF_UP.

Consultar la [guía de ajustes y migración](docs/adjustments.es.md) para fórmulas,
conversión de precisión y límites de reconciliación HKA. Para cambiar configuración, publicarla:

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

Usar `Cart::add()` para incorporar una línea comercial al carrito. Devuelve el
`CartItem` resultante. Los argumentos son código/id, nombre, cantidad, precio
unitario sin IVA, alícuota y opciones. Si se omite la alícuota se usa
`cart.default_aliquot`; una alícuota explícita debe existir en `cart.taxes`
(claves predeterminadas: 0, 1, 2 y 3).

**Identidad de línea = código/id del producto + options + alícuota.**
`name`, `price` y `qty` no forman parte de la identidad. Cualquier opción participa,
incluidas `image`, `color`, `size`, `presentation` y `variant`; no son simples
metadatos visuales.

```php
Cart::add('P001', 'Martillo', 1, 10.00, 0, ['image' => '/img/martillo.jpg']);
$item = Cart::add('P001', 'Martillo', 2, 10.00, 0, ['image' => '/img/martillo.jpg']);
// Una línea P001: qty = 3, options.image = /img/martillo.jpg
```

Repetir `add()` con la misma identidad completa reutiliza la línea y suma cantidad.
Otras opciones o alícuotas generan identidades diferentes:

```php
Cart::add('P001', 'Martillo', 1, 10.00, 0, []);
// Línea distinta de P001 con ['image' => '/img/martillo.jpg'].
```

**Consideración:** cambiar precio o nombre no crea otra identidad. Con el mismo
código, opciones y alícuota, `add()` acumula cantidad y reemplaza los demás atributos
por los recibidos en la nueva incorporación, incluidos precio y nombre. Para
editar una línea existente, utilizar `update()`.

La variante con array exige `id`, `name`, `qty` y `price`; `aliquot` y `options`
son opcionales:

```php
Cart::add(['id' => 'P002', 'name' => 'Alicate', 'qty' => 1, 'price' => 12.00]);
```

### update

Usar `Cart::update()` para modificar una línea existente: cantidad desde los botones
`+` / `−`, nombre, precio, alícuota, opciones o atributos proporcionados por un `Buyable`.
Acepta `rowId` o un código de producto que identifique una única línea.

Para el botón + del carrito, enviar la nueva cantidad:

```php
$item = Cart::get($rowId);
Cart::update($rowId, $item->qty + 1);

// Alternativa cuando P001 identifica una única línea:
$item = Cart::get('P001');
Cart::update('P001', $item->qty + 1);
```

No es necesario volver a llamar `add()` ni reenviar nombre, precio, alícuota,
opciones o imagen. `update()` parte de la línea existente y conserva los atributos
omitidos.

Ejemplo independiente con imagen:

```php
$item = Cart::add(
    'P001',
    'Martillo',
    1,
    10.00,
    0,
    ['image' => '/img/martillo.jpg']
);

// Posteriormente, desde el carrito:
Cart::update($item->rowId, 2);
// Sólo cambia qty; conserva id, name, price, aliquot y options.image.
```

Esto conserva la imagen y la identidad. Volver a ejecutar
`Cart::add('P001', 'Martillo', 1, 10.00, 0, [])` usaría otra identidad:
`[]` no equivale a `['image' => '/img/martillo.jpg']`.

Para otros cambios se admite un array parcial o un objeto que implemente `Buyable`:

```php
$item = Cart::update($rowId, ['name' => 'Nuevo nombre', 'price' => 11.00]);
$item = Cart::update($item->rowId, $product); // $product implementa Buyable
```

Cambiar opciones o alícuota puede cambiar el `rowId`; conservar el identificador
del objeto devuelto. Si la nueva identidad coincide con otra línea, se fusionan
las cantidades. Una cantidad cero o negativa elimina la línea.

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

`get()`, `update()` y `remove()` aceptan `rowId` o código/id del producto. El código
es válido únicamente cuando identifica una sola línea:

```php
Cart::get('P001');
Cart::update('P001', 2);
Cart::remove('P001');
```

Si P001 tiene variantes roja y azul, u otras combinaciones de opciones/alícuota,
`Cart::get('P001')` lanza `AmbiguousItemException`; lo mismo ocurre con `update()`
y `remove()` usando ese código. Se debe indicar el `rowId` de la línea deseada.

`rowId` identifica inequívocamente una línea del carrito. Conservarlo desde el
`CartItem` devuelto o desde `content()`; la aplicación no debe calcularlo ni predecirlo.

```php
$item = Cart::get($rowId);
```

La búsqueda intenta primero el `rowId` exacto y después el código. Si no existe
la línea, lanza `InvalidRowIDException`. La [guía de identidad](docs/adjustments.es.md#api-pública)
detalla las búsquedas explícitas `getByRowId()` y `getById()`.

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

`tax()` devuelve el IVA de las bases finales de productos y costos ITEM según el
driver, incluidos prorrateos y descuentos. Excluye propina y costos legacy.

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

Los importes de IVA son numéricos; `cart.format.decimals` controla su precisión. Los separadores sólo afectan la presentación.

**Si no est&aacute;s usando Facade, pero usas la inyecci&oacute;n de dependencia en tu (por ejemplo) Controller, tambi&eacute;n puedes simplemente obtener la propiedad de impuesto `$cart->tax`**

### subtotal

`subtotal()` devuelve la base final formateada de productos y costos ITEM, después
de prorrateos y descuentos. Excluye IVA, propina y costos legacy.

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

Los costos persisten por instancia, también con store/restore. La llamada de dos argumentos conserva el recargo legado sin IVA. Use `addCost('freight', 10, 'prorated')` o `addCost('installation', 20, 'item', 0)` para indicar su tratamiento. `getCost()` sigue formateado; `costDetails()` devuelve las operaciones estructuradas.

### getCost

Obtenga un costo adicional que agreg&oacute; mediante `addCost()`. Acepta el nombre del costo. Devuelve el precio formateado del costo.

```php
    Cart::getCost($name)
```

### remove

Eliminar una línea mediante su `rowId` o un código que identifique una única línea:

```php
Cart::remove($rowId);
// Alternativa para un código único:
Cart::remove('P001');
```

Un código ambiguo lanza `AmbiguousItemException`; utilizar `rowId` para las variantes.

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
