# Costos, descuentos e identidad de líneas

## Auditoría de la versión anterior

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

Los nuevos hashes incluyen alícuota normalizada además de código y opciones.
Cambiar precio o nombre no cambia identidad; agregar otra vez la misma identidad
suma cantidades y conserva el comportamiento de reemplazar otros atributos.
Se mantiene el ordenamiento superficial de opciones, no se canonicalizan arrays
anidados. Los rowId antiguos se conservan al leer, agregar o actualizar atributos
sin cambio de identidad. Cambiar identidad mueve sus descuentos; fusionar líneas
concatena sus descuentos y los aplica sobre la línea resultante. El descuento fijo
es por línea completa, no por unidad. Eliminar una línea elimina sus descuentos.
Usar `Cart::update()`/`Cart::setTax()` para cambiar identidad, no asignación directa
de campos de los objetos en la colección.

## Orden matemático

1. Base original de cada producto = cantidad × precio, cuantizada a centavos.
2. Cada costo PRORATED se reparte según las bases originales positivas; se suma a
   los productos y hereda su alícuota. Varios prorrateos usan los mismos pesos.
3. Descuentos de línea en orden de registro, sobre el saldo de esa línea.
4. Descuentos generales en orden de registro, sobre los saldos de productos;
   reparto proporcional por esas bases restantes.
5. Se incorporan las bases de los costos ITEM y se agrupan por alícuota.
6. Se calcula IVA mediante `CartItem::calculateTaxes()` y `config('cart.taxes')`.
7. Se suman base final + IVA + propina + costos legados sin modo.

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
conceptos ITEM facturables. `bases` está indexado por alícuota. La propina aparece en
`tip` y en observaciones. **Para facturar ajustes usar `summary()`**, pues
`content()` y los getters propios de `CartItem` conservan los precios originales.
`totalTaxes($input, $taxes)` calcula solamente los productos recibidos, sin los
metadatos del documento; `tax()` devuelve el IVA final con ajustes.

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
- GENERAL devuelve todas las alícuotas y calcula IVA agrupado por base, corrigiendo
  la antigua agrupación indiscriminada. Redondeo unitario vs agrupado puede cambiar
  centavos. HKA conserva agrupación; PNP ahora usa la misma base para subtotal e IVA.
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

Base GENERAL 114.05; exenta 49.50; subtotal 163.55; IVA GENERAL 18.25;
propina 5.00; total 186.80. Costos registrados: 40.00. Descuento efectivo: 21.45.

## Verificación y archivos

Ejecutar `composer install` y `composer test`. La suite usa sesiones reales de
Illuminate y SQLite en memoria, e incluye identidad, acumulación, búsqueda,
ambigüedad, cambio de rowId, alícuotas, redondeo, descuentos, observaciones,
aislamiento, destroy, snapshots antiguos y nuevos, merge y escenario completo.

Archivos de implementación: `src/Cart.php`, `src/CartItem.php`,
`src/ShoppingcartServiceProvider.php`; nuevos `src/CartAdjustments.php`,
`src/Money.php`, `src/Exceptions/AmbiguousItemException.php`. Infraestructura:
`composer.json`, `phpunit.xml`, `.gitignore`, `tests/bootstrap.php`, `tests/CartTest.php`.
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

Resultado de ejecución local (PHP 8.2.28, PHPUnit 10.5.64):

| Dependencias Illuminate | Tests | Assertions | Resultado |
| --- | ---: | ---: | --- |
| 10.49.0 | 47 | 198 | Correcto |
| 11.51.0 | 47 | 198 | Correcto |

También pasan `php -l` en src/tests y `git diff --check`. `composer validate`
acepta el manifiesto y mantiene la advertencia preexistente sobre `version`.
El entorno final queda con Illuminate 11.51.0. No había suite anterior para ejecutar;
las pruebas nuevas incluyen los comportamientos previos que se conservan.
