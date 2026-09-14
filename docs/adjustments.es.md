# Costos, descuentos e identidad de líneas

## Auditoría de la versión anterior

Esta sección describe la versión histórica anterior a `sprint-1`. Para el uso
actual, consultar [API pública](#api-pública) y [Orden matemático](#orden-matemático).

`add()` construía un `CartItem` y sumaba cantidades si la colección contenía su
`rowId`. Este era `md5(id . serialize(options))`, con `ksort()` solamente en el
primer nivel de opciones. Nombre, precio, cantidad y alícuota no intervenían.
Dos alícuotas podían fusionarse incorrectamente. La última incorporación
reemplazaba los otros atributos de la línea.

`get()` buscaba exclusivamente la clave de colección. `update()` modificaba el
objeto y movía su clave cuando cambiaban las opciones; podía dejar inconsistencias
al eliminar simultáneamente una línea y cambiar su identidad. `remove()` usaba
`get()`. No había resolución por código ni descuentos.

`addCost()` acumulaba montos en `extraCosts`, una propiedad temporal compartida
por las instancias de un mismo objeto. `getCost()` devolvía el monto formateado.
Los costos solamente aumentaban el total, sin base ni IVA, y se perdían al recrear
el servicio. `destroy()` solamente borraba productos.

`subtotal()` sumaba precios por cantidades. HKA redondeaba cada base de línea con
`sprintf`, agrupaba cuatro alícuotas y redondeaba sus impuestos. PNP truncaba las
bases e impuestos, pero su total seguía otra ruta. GENERAL acumulaba impuestos
formateados en una sola categoría y calculaba totales desde precios con impuesto
unitario. Eso podía producir discrepancias o errores al usar separadores de miles.
La propiedad pública local `priceTax` impedía ejecutar su getter mágico y quedaba
sin inicializar. Se conserva esa propiedad, ahora inicializada.

La sesión almacenaba `Collection<CartItem>` en `cart.<instancia>`. `store()`
serializaba solamente esa colección; `restore()` superponía las líneas guardadas
y eliminaba el registro de base de datos. `merge()` sumaba cantidades sin consumir
el registro. Faltaba importar `Contracts\InstanceIdentifier`, la tabla configurada
se ignoraba y `fromBuyable()` pasaba opciones en la posición de alícuota.
No había tests ni dependencias de desarrollo.

## Estructura y persistencia

Se conserva `cart.<instancia>` como colección de productos. Una clave separada,
`cart_metadata.<instancia>`, contiene tres listas de arrays:

```php
[
    'costs' => [
        ['name' => 'freight', 'amount' => 10, 'cents' => 1000,
         'mode' => 'prorated', 'aliquot' => null, 'description' => 'Flete'],
    ],
    'discounts' => [
        ['rowId' => null, 'type' => 'percentage', 'value' => 10,
         'concept' => 'Pronto pago'],
    ],
    'observations' => [
        ['type' => 'manual', 'text' => 'Entregar por la tarde'],
    ],
]
```

Una operación agrega un registro; varias operaciones con el mismo nombre de
costo coexisten. No son modelos Eloquent. El trait `CartAdjustments` contiene la
API documental y `Money` encapsula precisión y distribución.

Los montos efectivos de los descuentos y las observaciones automáticas se derivan
de estos registros cada vez que se consulta: nunca quedan desactualizados después
de cambiar cantidades. No se analizan strings para hacer cálculos.

`store()` guarda un sobre `['version' => 2, 'content' => ..., 'metadata' => ...]`
en la columna existente. No requiere migración. `restore()` también lee colecciones
antiguas. Conserva la superposición de productos por rowId y agrega los metadatos
guardados después de los existentes; después consume el registro. Para reemplazar
todo el documento, llamar `destroy()` antes de `restore()`.

`merge()` suma productos y agrega costos, descuentos y observaciones; adapta los
descuentos al identificador de la línea que sobreviva. Como antes, no consume el
registro: repetir `merge()` vuelve a incorporar cantidades y metadatos.
`destroy()` limpia ambas claves de la instancia. El borrado configurado al cerrar
sesión elimina también los metadatos de todas las instancias.

## API pública

```php
Cart::get($rowIdOrCode);
Cart::update($rowIdOrCode, ['qty' => 2]);
Cart::remove($rowIdOrCode);
Cart::getByRowId($rowId); // exclusivamente clave interna
Cart::getById('000123');  // una línea o excepción; no devuelve colección

Cart::addCost('freight', 15, 'prorated');
Cart::addCost('installation', 20, 'item', 0, 'Instalación');
Cart::addCost('tip', 5);

// PHP 8+: amount es un alias de price; no suministrar ambos.
Cart::addCost(name: 'packaging', amount: 10, mode: 'item', aliquot: 1);

Cart::addDiscountToItem('A001', 'percentage', 5, 'Descuento especial');
Cart::addDiscountToItem($rowId, 'fixed', 2, 'Cupón de línea');
Cart::addDiscount('percentage', 10, 'Pronto pago');
Cart::addDiscount('fixed', 1, 'Cupón de documento');
Cart::addObservation('Entregar por la tarde');

Cart::content();          // Collection<CartItem>, atributos originales
Cart::costs();            // colección estructurada de operaciones
Cart::costDetails('freight'); // colección; puede haber varias operaciones
Cart::getCost('freight'); // suma formateada, compatible
Cart::totalCost();        // importe numérico de todos los costos, incluida propina
Cart::discounts();        // registros + amount, cents y allocations por rowId
Cart::totalDiscount();    // monto numérico efectivamente descontado
Cart::observations();    // manuales, propinas, descuentos y consolidado
Cart::summary();         // liquidación numérica completa
Cart::subtotal();         // base final formateada, incluidos costos ITEM
Cart::tax();              // array por nombre de alícuota, IVA y value numérico
Cart::total();            // total final formateado
```

Constantes disponibles en `JeleDev\Shoppingcart\Cart`: `COST_FREIGHT`,
`COST_INSTALLATION`, `COST_PACKAGING`, `COST_INSURANCE`, `COST_TIP`, `COST_ITEM`,
`COST_PRORATED`. Se conserva `COST_TRANSACTION` y los nombres personalizados.

La resolución busca primero una coincidencia exacta por `rowId`; después compara
el código como string, sin conversión numérica: `000123` es diferente de `123`.
Entero `123` y string `"123"` designan el mismo código. Cero es válido.
Varias coincidencias producen `AmbiguousItemException` antes de modificar nada;
ninguna produce `InvalidRowIDException`. La regla también cubre `associate()`,
`setTax()` y descuentos de línea.

La identidad actual es **código/id + options + alícuota**. Nombre, precio y cantidad
no intervienen. `add()` incorpora una línea comercial: si encuentra la misma
identidad completa, conserva su rowId, suma cantidades y reemplaza los demás
atributos por los recibidos, incluso cuando cambia el precio o el nombre.
Todas las opciones participan, incluidas `image`, `color`, `size`, `presentation`
y `variant`: una imagen no es un dato visual ajeno a la identidad.

```php
Cart::add('P001', 'Martillo', 1, 10.00, 0, ['image' => '/img/martillo.jpg']);
$item = Cart::add('P001', 'Martillo', 2, 10.00, 0, ['image' => '/img/martillo.jpg']);
// Una línea con qty = 3 y la imagen conservada.
Cart::add('P001', 'Martillo', 1, 10.00, 0, []);
// Otra identidad: ahora el código P001 es ambiguo; usar rowId.
```

Para modificar una línea existente usar `update()`: cantidad desde los botones
`+` / `−`, nombre, precio, alícuota, opciones o atributos de un `Buyable`. Conserva los
atributos omitidos; para incrementar cantidad no hay que reenviar imagen ni otros
datos mediante `add()`:

```php
$item = Cart::get($rowId);
Cart::update($rowId, $item->qty + 1);
// Conserva id, name, price, aliquot y options.image.
// También se admite el código si sólo existe una línea con ese código.
```

`rowId` identifica inequívocamente la línea. Conservar el devuelto por `add()` o
`update()`, o consultarlo en `content()`; no calcularlo ni predecirlo. Si hay
variantes P001 roja/azul, `get('P001')`, `update('P001', 2)` y `remove('P001')`
lanzan `AmbiguousItemException` y requieren el rowId correspondiente.

Se mantiene el ordenamiento superficial de opciones, no se canonicalizan arrays
anidados. Los rowId antiguos se conservan al leer, agregar o actualizar atributos
sin cambio de identidad. Cambiar identidad mueve sus descuentos; fusionar líneas
concatena sus descuentos y los aplica sobre la línea resultante. El descuento fijo
es por línea completa, no por unidad. Eliminar una línea elimina sus descuentos.
Usar `Cart::update()`/`Cart::setTax()` para cambiar identidad, no asignación directa
de campos de los objetos en la colección.

## Orden matemático

1. Base original de cada producto = cantidad × precio, cuantizada a centavos:
   HALF_UP en GENERAL/HKA; truncamiento hacia cero en PNP.
2. Cada costo PRORATED se reparte según las bases originales positivas; se suma a
   los productos y hereda su alícuota. Varios prorrateos usan los mismos pesos.
3. Descuentos de línea en orden de registro, sobre el saldo de esa línea.
4. Descuentos generales en orden de registro, sobre los saldos de productos;
   reparto proporcional por esas bases restantes.
5. Cada costo ITEM se incorpora como otra línea fiscal, separado de los productos.
6. Sobre las bases finales, cada driver aplica su estrategia de IVA indicada abajo,
   mediante `CartItem::calculateTaxes()` y `config('cart.taxes')`.
7. Se suman base final + IVA + propina + costos legados sin modo.

Hasta obtener las bases finales se comparte el orden de prorrateos y descuentos,
con la cuantización inicial propia del driver. La base final de producto es su
base original cuantizada + prorrateos asignados − descuentos aplicables; los
ajustes se calculan y reparten en centavos. Desde allí difiere la acumulación:

| Driver | Base por línea de producto | IVA sobre las bases finales |
| --- | --- | --- |
| GENERAL | HALF_UP a 2 decimales | Calcula y redondea HALF_UP el IVA de cada línea fiscal; después suma por alícuota |
| HKA | HALF_UP a 2 decimales | Suma bases por alícuota y calcula una vez su IVA, redondeado HALF_UP |
| PNP | Truncamiento hacia cero a 2 decimales | Suma bases truncadas por alícuota y trunca hacia cero el IVA acumulado |

Cada ITEM calcula IVA individual en GENERAL y se incorpora a la base de su
alícuota en HKA/PNP. Sigue en `costLines`, sin convertirse en `CartItem`. Su importe
ya llega cuantizado a centavos por `addCost()` con HALF_UP en todos los drivers;
PNP no vuelve a truncar el importe original registrado. PRORATED ya forma parte
de las bases finales de productos y no se vuelve a sumar al total.

Los descuentos generales excluyen costos ITEM, propinas y costos legados. Los
porcentajes deben estar entre 0 y 100; los fijos no pueden ser negativos. Un fijo
superior al saldo se limita al saldo: las bases nunca quedan negativas. Se registra
el descuento solicitado y se devuelve también el importe efectivo. Los descuentos
generales en un carrito vacío tienen importe efectivo cero.

Un ITEM exige alícuota explícita del catálogo. Un PRORATED rechaza alícuota propia
y no genera una línea adicional. Un prorrateo positivo sin base distribuible lanza
`DomainException` al liquidar; puede registrarse antes de agregar productos.
La propina rechaza modo y alícuota, nunca genera IVA ni un `CartItem`, y produce una
observación `type=tip`, `description`, `amount`, `text`.

`summary()['lines']` permite construir las líneas fiscales de productos: `original`,
`prorated`, `discount`, `base`, `aliquot`, `rowId`, `id`. `costLines` contiene los
conceptos ITEM facturables. `bases` siempre muestra las bases finales agrupadas
por alícuota para consulta/facturación, incluso en GENERAL, cuyo IVA se calcula
por línea. `taxes` usa el nombre configurado de cada impuesto, con `IVA` como
porcentaje y `value` como importe calculado por el driver; `tax` suma esos importes.
Por tanto, GENERAL y HKA pueden devolver **bases idénticas e impuestos diferentes**:
es correcto y esperado. La propina aparece en
`tip` y en observaciones. **Para facturar ajustes usar `summary()`**, pues
`content()` y los getters propios de `CartItem` conservan los precios originales.
`totalTaxes($input, $taxes)` calcula solamente los productos recibidos, sin los
metadatos del documento, con la misma estrategia de cada driver: GENERAL por
fila completa, HKA por bases acumuladas y PNP por bases truncadas acumuladas.
`tax()` devuelve el IVA final con ajustes.

### Ejemplos fiscales de centavos

Con dos líneas distintas de 0.03 e IVA del 16%:

```text
GENERAL: 0.03 × 16% = 0.0048 → 0.00 en cada línea; suma IVA = 0.00
HKA: (0.03 + 0.03) × 16% = 0.0096 → 0.01
Bases GENERAL = 0.06; bases HKA = 0.06
IVA GENERAL = 0.00; IVA HKA = 0.01
```

GENERAL toma la línea completa, no cada unidad física: cantidad 2 × precio 10.23
= base 20.46; IVA 16% = 3.2736 → **3.27**. Calcular primero IVA unitario redondeado
daría 1.64 × 2 = 3.28 y no corresponde a esta estrategia.

Con dos líneas distintas de 0.039 e IVA del 16%:

```text
HKA: 0.039 → 0.04 cada línea; base = 0.08; IVA = 0.0128 → 0.01
PNP: 0.039 → 0.03 cada línea; base = 0.06; IVA = 0.0096 → 0.00
```

Estos casos corresponden a `tests/FiscalDriverTest.php`. Las líneas deben tener
identidades distintas; dos `add()` con la misma identidad acumulan cantidad y
constituyen una sola línea fiscal.

## Precisión y cambios de comportamiento

El reparto usa centavos enteros, cociente y resto exactos, sin multiplicaciones que
desborden; asigna los centavos restantes al mayor resto y desempata por rowId en
orden lexicográfico. La suma de allocations coincide exactamente con el importe
cuantizado. No depende del orden de inserción de productos.

Precios, cantidades y porcentajes siguen aceptando valores numéricos/floats por
compatibilidad. `Money::cents()` elimina ruido binario hasta seis decimales del
valor escalado y cuantiza a dos decimales. HKA y GENERAL usan mitad hacia arriba;
PNP trunca bases e IVA hacia cero, corrigiendo casos como `0.29 * 100`.
Los costos y descuentos usan centavos y mitad hacia arriba en todos los drivers.
Los cálculos soportan importes individuales hasta 1.000.000.000 y enteros de 64 bits;
no constituyen un motor decimal de precisión arbitraria. Los floats retornados
pueden mostrar la representación binaria usual al sumarlos fuera del paquete:
para comparar importes usar centavos, no igualdad de sumas de floats.
`cart.format` afecta exclusivamente presentación, no la precisión contable.

Se conservan firmas posicionales, fachadas, eventos de operaciones habituales,
colecciones de productos, count por cantidad, sesiones antiguas y getCost formateado.
`addCost($name, $price)` sin modo mantiene su recargo histórico sin IVA ni subtotal,
ahora persistente. Para nuevas operaciones especificar ITEM o PRORATED.

Cambios a considerar al actualizar:

- Los nuevos rowId incluyen alícuota; no generar ni predecir hashes en la aplicación.
- GENERAL devuelve todas las alícuotas y suma el IVA redondeado de cada línea
  fiscal final. No usa IVA unitario por cantidad ni IVA sobre bases agrupadas.
  HKA calcula IVA después de agrupar bases por alícuota; PNP agrupa bases truncadas
  y trunca el IVA final. Estas estrategias pueden producir diferencias de centavos.
- Subtotal incorpora ajustes y costos ITEM. No volver a sumar totalCost ni restar
  totalDiscount al total: ya están incluidos, salvo la separación informativa.
- `calculateTaxes()` retorna números crudos; los métodos de presentación permanecen
  formateados. La precisión de cálculo es de dos decimales aunque el formato cambie.
- Los snapshots v2 requieren esta versión al restaurarse; versiones anteriores no
  entienden el sobre. Esta versión sí lee los snapshots antiguos.
- Se rechazan precios/costos negativos, alícuotas inválidas y valores no finitos.
- Los métodos protegidos antiguos de totales ya no son puntos de extensión de la
  liquidación pública; subclases que los sobrescriban deben adaptar `summary()`.

La declaración antigua de PHP/Laravel ya era inconsistente: exige Collections 10/11
aunque anuncia Laravel 7–11 y PHP 7.2. No se amplía artificialmente esa compatibilidad.
La suite nueva necesita PHP 8.1+ y prueba las dependencias Laravel 10/11.

## Escenario de referencia

| Concepto | A GENERAL | B EXEMPT | Instalación GENERAL |
| --- | ---: | ---: | ---: |
| Base original | 100.00 | 50.00 | 20.00 |
| Flete prorrateado | 10.00 | 5.00 | 0.00 |
| Descuento de línea 5% | 5.50 | 0.00 | 0.00 |
| Descuento general 10% | 10.45 | 5.50 | 0.00 |
| Base final | 94.05 | 49.50 | 20.00 |

Con driver HKA: base de alícuota GENERAL 114.05; exenta 49.50; subtotal 163.55;
IVA de alícuota GENERAL 18.25;
propina 5.00; total 186.80. Costos registrados: 40.00. Descuento efectivo: 21.45.

## Verificación y archivos

Ejecutar `composer install` y `composer test`. La suite usa sesiones reales de
Illuminate y SQLite en memoria, e incluye identidad, acumulación, búsqueda,
ambigüedad, cambio de rowId, alícuotas, redondeo, descuentos, observaciones,
aislamiento, destroy, snapshots antiguos y nuevos, merge y escenario completo.

Archivos de implementación: `src/Cart.php`, `src/CartItem.php`,
`src/ShoppingcartServiceProvider.php`; nuevos `src/CartAdjustments.php`,
`src/Money.php`, `src/Exceptions/AmbiguousItemException.php`. Infraestructura:
`composer.json`, `phpunit.xml`, `.gitignore`, `tests/bootstrap.php`, `tests/CartTest.php`
y `tests/FiscalDriverTest.php`. Este último cubre las diferencias fiscales, bases
iguales con IVA distinto, cantidades mayores que uno, alícuotas mixtas, ITEM,
PRORATED, descuentos y exclusión de propina/legacy.
Se actualizan los enlaces de ambos README y esta guía. No se alteran las tasas ni
la migración existente.

Métodos agregados a Cart: `getByRowId`, `getById`, `costs`, `costDetails`,
`totalCost`, `addDiscountToItem`, `addDiscount`, `discounts`, `totalDiscount`,
`addObservation`, `observations`, `summary`. CartItem agrega `identity()`.

Métodos modificados de Cart: `add`, `addCost`, `getCost`, `get`, `update`, `remove`,
`setTax`, `content`, `destroy`, `subtotal`, `tax`, `total`, los cálculos internos
de drivers, `store`, `restore`, `merge`, `addCartItem`, `getContent`,
`createCartItem`, `getTableName`. En CartItem: constructor, `setQuantity`,
`updateFromBuyable`, `updateFromArray`, `setTaxRate`, `fromBuyable`, `fromArray`,
`generateRowId` y cálculos numéricos de los drivers. `associate()` adquiere la
resolución por código a través de `get()` sin duplicar búsquedas.

Resultado histórico de la suite inicial (PHP 8.2.28, PHPUnit 10.5.64), anterior
a las pruebas específicas de drivers:

| Dependencias Illuminate | Tests | Assertions | Resultado |
| --- | ---: | ---: | --- |
| 10.49.0 | 47 | 198 | Correcto |
| 11.51.0 | 47 | 198 | Correcto |

En aquella validación también pasaron `php -l` en src/tests y `git diff --check`.
`composer validate` aceptó el manifiesto con la advertencia preexistente sobre
`version`. Esos resultados históricos no representan la suite fiscal ampliada.

La validación documental actual con Illuminate 11.51.0, PHP 8.2.28 y PHPUnit
10.5.64 ejecutó `composer test`: **75 tests y 871 assertions**, todos correctos.
Incluye los 47 tests iniciales y 28 casos fiscales adicionales.