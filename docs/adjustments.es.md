# Costos, descuentos y liquidación fiscal

Esta guía documenta las funciones avanzadas de liquidación del carrito: costos adicionales, prorrateos, descuentos, observaciones, precisión monetaria, cálculo fiscal y persistencia.

Para el uso general de `Cart::add()`, `Cart::update()`, identidad de líneas, `rowId` y configuración básica de los drivers fiscales, consulte `README.md` o `README.es.md`.

---

## Costos adicionales

El paquete permite registrar costos adicionales asociados al documento mediante:

```php
Cart::addCost($name, $price, $mode = null, $aliquot = null, $description = null);
```

También puede utilizarse `amount` como argumento nombrado en PHP 8+:

```php
Cart::addCost(
    name: 'packaging',
    amount: 10,
    mode: 'item',
    aliquot: 1
);
```

Los costos se almacenan como operaciones independientes. Varias operaciones con el mismo nombre pueden coexistir.

Los modos principales son:

* `ITEM`
* `PRORATED`

La propina tiene un tratamiento especial y los costos registrados sin modo mantienen el comportamiento legacy por compatibilidad.

Constantes disponibles en `JeleDev\Shoppingcart\Cart`:

```php
Cart::COST_FREIGHT;
Cart::COST_INSTALLATION;
Cart::COST_PACKAGING;
Cart::COST_INSURANCE;
Cart::COST_TIP;

Cart::COST_ITEM;
Cart::COST_PRORATED;
```

También se conserva `COST_TRANSACTION` y pueden utilizarse nombres personalizados.

### Costo ITEM

Un costo `ITEM` representa un concepto facturable independiente.

Ejemplo:

```php
Cart::addCost(
    'installation',
    20,
    Cart::COST_ITEM,
    0,
    'Instalación'
);
```

El costo requiere una alícuota explícita existente en `config('cart.taxes')`.

Un ITEM:

* incrementa el subtotal;
* forma parte de la base fiscal;
* puede generar IVA según su alícuota;
* aparece en `summary()['costLines']`;
* no se convierte internamente en un `CartItem`.

Fiscalmente se comporta como una línea adicional del documento.

Su tratamiento depende del driver:

| Driver  | Tratamiento del costo ITEM                                         |
| ------- | ------------------------------------------------------------------ |
| GENERAL | Calcula el IVA del ITEM como una línea fiscal independiente        |
| HKA     | Incorpora su base a la base acumulada de su alícuota               |
| PNP     | Incorpora su base a la alícuota siguiendo la estrategia fiscal PNP |

El importe registrado mediante `addCost()` ya se cuantiza a centavos con HALF_UP. En PNP no se vuelve a truncar el importe original del costo ITEM.

### Costo PRORATED

Un costo `PRORATED` se distribuye proporcionalmente entre las líneas de productos.

Ejemplo:

```php
Cart::addCost(
    'freight',
    15,
    Cart::COST_PRORATED
);
```

No lleva una alícuota propia porque no constituye una línea fiscal independiente.

El importe se distribuye entre las bases originales positivas de los productos y cada parte asignada hereda la alícuota del producto correspondiente.

Conceptualmente:

```text
base original del producto
+ costo PRORATED asignado
= nueva base del producto
```

Si existen varios costos PRORATED, todos utilizan las mismas bases originales como pesos de distribución.

La suma de las asignaciones siempre coincide exactamente con el importe del costo cuantizado.

Una vez incorporado a las bases de los productos, el PRORATED no se vuelve a sumar al subtotal o al total.

Un costo PRORATED positivo puede registrarse antes de agregar productos. Si al momento de liquidar no existe una base positiva sobre la cual distribuirlo, se produce `DomainException`.

### Propina

La propina se registra mediante:

```php
Cart::addCost('tip', 5);
```

La propina:

* incrementa el total final;
* no incrementa la base fiscal;
* no genera IVA;
* no se prorratea;
* no genera un `CartItem`;
* no aparece como costo ITEM;
* genera una observación de tipo `tip`.

No admite modo ni alícuota.

Puede consultarse separadamente mediante:

```php
Cart::summary()['tip'];
```

### Costos legacy

Por compatibilidad se mantiene:

```php
Cart::addCost($name, $price);
```

cuando el nombre no corresponde al tratamiento especial de propina y no se proporciona un modo.

Este costo conserva el comportamiento histórico:

* incrementa el total;
* no incrementa el subtotal;
* no forma parte de una base fiscal;
* no genera IVA.

Para nuevas integraciones se recomienda indicar explícitamente `ITEM` o `PRORATED` cuando corresponda.

### Consultar costos

La API disponible incluye:

```php
Cart::costs();
Cart::costDetails('freight');
Cart::getCost('freight');
Cart::totalCost();
```

`costs()` devuelve las operaciones estructuradas.

`costDetails()` permite obtener las operaciones asociadas a un nombre. Puede devolver varias porque los costos del mismo tipo pueden registrarse más de una vez.

`getCost()` conserva por compatibilidad el retorno formateado y representa la suma de las operaciones del nombre solicitado.

`totalCost()` devuelve el importe numérico total de los costos registrados, incluida la propina.

> `totalCost()` es informativo. No debe sumarse nuevamente a `Cart::total()`, porque los costos ya son incorporados por la liquidación según su modalidad.

---

## Descuentos

El paquete admite descuentos:

* sobre una línea específica;
* sobre el documento en general.

Pueden coexistir múltiples descuentos y se aplican en el orden en que fueron registrados.

Los tipos disponibles son:

```text
percentage
fixed
```

### Descuento de línea

Puede aplicarse mediante código de producto inequívoco:

```php
Cart::addDiscountToItem(
    'A001',
    'percentage',
    5,
    'Descuento especial'
);
```

o mediante `rowId`:

```php
Cart::addDiscountToItem(
    $rowId,
    'fixed',
    2,
    'Cupón de línea'
);
```

Los descuentos de línea se aplican después de incorporar los costos PRORATED asignados a esa línea.

Por tanto:

```text
base original
+ PRORATED
- descuentos de línea
= saldo de línea
```

Un descuento fijo corresponde a la línea completa, no a cada unidad individual.

### Descuento general

Se registra mediante:

```php
Cart::addDiscount(
    'percentage',
    10,
    'Pronto pago'
);
```

o:

```php
Cart::addDiscount(
    'fixed',
    1,
    'Cupón de documento'
);
```

Los descuentos generales se aplican después de los descuentos de línea.

Se distribuyen proporcionalmente entre los saldos restantes de los productos.

No se aplican sobre:

* costos ITEM;
* propinas;
* costos legacy.

### Reglas de los descuentos

Un porcentaje debe encontrarse entre:

```text
0 y 100
```

Un descuento fijo no puede ser negativo.

Cuando un descuento fijo supera el saldo disponible, el importe efectivo se limita al saldo.

Las bases fiscales nunca quedan negativas.

El paquete diferencia entre:

```text
descuento solicitado
```

y:

```text
descuento efectivo
```

Esto permite conservar el registro original de la operación aunque el importe aplicable haya tenido que limitarse.

Los descuentos generales registrados sobre un carrito sin productos tienen importe efectivo cero.

### Consultar descuentos

```php
Cart::discounts();
Cart::totalDiscount();
```

`discounts()` devuelve los registros y sus importes efectivos, incluyendo las asignaciones por `rowId` cuando corresponda.

`totalDiscount()` devuelve el monto numérico efectivamente descontado.

> `totalDiscount()` es informativo. No debe volver a restarse de `subtotal()` o `total()`, porque la liquidación ya incorporó los descuentos.

---

## Observaciones

Se pueden agregar observaciones manuales:

```php
Cart::addObservation('Entregar por la tarde');
```

y consultarlas mediante:

```php
Cart::observations();
```

Las observaciones pueden incluir información generada por:

* observaciones manuales;
* propinas;
* descuentos;
* consolidación de descuentos.

Los importes utilizados para generar observaciones automáticas se calculan a partir del estado actual del documento.

Las observaciones no constituyen la fuente contable de los cálculos. El paquete nunca necesita interpretar el texto de una observación para determinar costos, descuentos, impuestos o totales.

---

## Liquidación con `summary()`

Para construir el documento fiscal final debe utilizarse:

```php
$summary = Cart::summary();
```

`content()` continúa representando los `CartItem` comerciales y sus valores originales.

Los costos PRORATED y descuentos no modifican destructivamente los precios originales almacenados en esos objetos.

Por esa razón:

> Para facturar un carrito que contenga costos, prorrateos o descuentos, utilice `summary()` como fuente de las bases fiscales finales.

### Líneas de productos

```php
$summary['lines'];
```

contiene las líneas fiscales finales de los productos.

Cada línea incluye información equivalente a:

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

Conceptualmente:

```text
original
+ prorated
- discount
= base
```

`base` es el valor fiscal final que debe utilizarse para esa línea.

### Costos ITEM

```php
$summary['costLines'];
```

contiene los costos ITEM que deben considerarse conceptos facturables adicionales.

Estos conceptos permanecen separados de los `CartItem`.

### Bases por alícuota

```php
$summary['bases'];
```

representa las bases fiscales finales agrupadas por alícuota.

Por ejemplo:

```php
[
    0 => 114.05,
    1 => 49.50,
]
```

Estas bases agrupadas son útiles para consulta y construcción del documento fiscal.

Es importante distinguir las bases agrupadas de la estrategia utilizada para calcular el IVA.

En particular:

```text
summary()['bases'] de GENERAL
```

puede ser idéntico a:

```text
summary()['bases'] de HKA
```

aunque sus impuestos sean diferentes.

Esto es correcto porque GENERAL calcula IVA por línea mientras HKA calcula IVA sobre bases acumuladas.

### Impuestos

```php
$summary['taxes'];
```

utiliza los nombres configurados en:

```php
config('cart.taxes');
```

Cada impuesto contiene:

```php
[
    'IVA'   => 16.00,
    'value' => 18.25,
]
```

donde:

* `IVA` es el porcentaje configurado;
* `value` es el importe calculado según la estrategia del driver.

```php
$summary['tax'];
```

representa la suma monetaria de los impuestos calculados.

### Totales

La liquidación expone también:

```php
$summary['subtotal'];
$summary['tax'];
$summary['tip'];
$summary['totalCost'];
$summary['totalDiscount'];
$summary['total'];
```

No debe reconstruirse el total sumando nuevamente `totalCost()` o restando nuevamente `totalDiscount()`.

Estos valores ya fueron incorporados en la liquidación.

---

## Orden matemático

La liquidación se realiza conceptualmente en el siguiente orden:

1. Se calcula la base original de cada producto:

```text
cantidad × precio
```

2. La base se cuantiza según el driver:

```text
GENERAL → HALF_UP
HKA     → HALF_UP
PNP     → truncamiento hacia cero
```

3. Los costos PRORATED se distribuyen proporcionalmente entre las bases originales positivas.

4. Los costos PRORATED asignados se incorporan a las bases de los productos.

5. Se aplican los descuentos de línea en su orden de registro.

6. Se aplican los descuentos generales en su orden de registro y se distribuyen proporcionalmente entre los productos.

7. Se obtienen las bases fiscales finales de los productos.

8. Los costos ITEM se incorporan como líneas fiscales adicionales.

9. Cada driver aplica su estrategia de cálculo del IVA.

10. Se incorporan propina y costos legacy según sus reglas.

Conceptualmente:

```text
cantidad × precio
       │
       ▼
 base original
       │
       ▼
 + PRORATED
       │
       ▼
 - descuento de línea
       │
       ▼
 - descuento general asignado
       │
       ▼
 BASE FISCAL FINAL
       │
       ├───────────────┐
       │               │
 productos          costos ITEM
       │               │
       └───────┬───────┘
               │
               ▼
      estrategia del driver
               │
               ▼
              IVA
               │
               ▼
       subtotal + IVA
               │
               ▼
      + propina + legacy
               │
               ▼
             TOTAL
```

---

## Estrategias fiscales

El driver configurado mediante:

```php
config('cart.driver');
```

no determina únicamente si un número se redondea o se trunca.

También determina cuándo se acumulan las bases antes de calcular el IVA.

| Driver  | Base por línea                        | Cálculo del IVA                                                                       |
| ------- | ------------------------------------- | ------------------------------------------------------------------------------------- |
| GENERAL | HALF_UP a 2 decimales                 | Calcula y redondea IVA por línea fiscal final; después suma                           |
| HKA     | HALF_UP a 2 decimales                 | Agrupa bases finales por alícuota y calcula el IVA sobre la base acumulada            |
| PNP     | Truncamiento hacia cero a 2 decimales | Agrupa bases truncadas por alícuota y trunca el IVA calculado sobre la base acumulada |

### GENERAL

GENERAL utiliza como unidad fiscal la línea completa.

Para un producto:

```text
cantidad × precio
```

produce la base inicial de la línea.

Después de prorrateos y descuentos se obtiene su base fiscal final.

El IVA se calcula independientemente para cada línea:

```text
IVA línea = base final línea × porcentaje
```

y se redondea HALF_UP a dos decimales.

Finalmente se suman los IVA de las líneas correspondientes a cada alícuota.

GENERAL no calcula el IVA unitario para posteriormente multiplicarlo por la cantidad.

Tampoco calcula un único IVA sobre la suma de todas las bases de la alícuota.

### HKA

HKA cuantiza cada base de línea mediante HALF_UP a dos decimales.

Después de obtener las bases fiscales finales:

```text
línea A
línea B
línea C
   │
   ▼
agrupar por alícuota
   │
   ▼
base acumulada
   │
   ▼
calcular IVA
   │
   ▼
HALF_UP a 2 decimales
```

Los costos ITEM se incorporan a la base acumulada de su alícuota.

### PNP

PNP utiliza truncamiento hacia cero para la cuantización de las bases de productos.

Después:

```text
bases truncadas
      │
      ▼
agrupar por alícuota
      │
      ▼
base acumulada
      │
      ▼
calcular IVA
      │
      ▼
truncar a 2 decimales
```

Los costos ITEM registrados ya llegan cuantizados a centavos por `addCost()` y participan en la base de su alícuota.

---

## Precisión monetaria

Los cálculos de ajustes utilizan centavos enteros siempre que es posible.

La clase interna `Money` centraliza:

* conversión a centavos;
* HALF_UP;
* truncamiento;
* distribución proporcional;
* tratamiento del residuo.

Esto evita utilizar valores formateados como parte de operaciones matemáticas.

`number_format()` y la configuración:

```php
config('cart.format');
```

se utilizan para presentación y no determinan la precisión contable.

### Distribución exacta

Los costos PRORATED y descuentos generales pueden requerir distribuir una cantidad de centavos entre varias líneas.

El paquete utiliza distribución proporcional y asigna los centavos residuales determinísticamente.

La suma de todas las asignaciones siempre debe cumplir:

```text
suma de allocations
=
importe total cuantizado
```

Cuando existen restos equivalentes, se utiliza `rowId` como criterio determinista de desempate.

El resultado no depende del orden en que los productos fueron incorporados al carrito.

### Floats

Por compatibilidad, la API continúa aceptando valores numéricos y `float`.

Internamente `Money::cents()` reduce el ruido binario antes de cuantizar los valores.

Los `float` devueltos por una API PHP pueden volver a mostrar representación binaria al realizar operaciones externas.

Para verificaciones monetarias exactas se recomienda comparar centavos en lugar de igualdad directa entre sumas de `float`.

---

## Persistencia

Los productos continúan almacenándose en la sesión bajo:

```text
cart.<instancia>
```

Los metadatos documentales se almacenan separadamente bajo:

```text
cart_metadata.<instancia>
```

Los metadatos incluyen:

```php
[
    'costs' => [],
    'discounts' => [],
    'observations' => [],
]
```

Esto permite conservar costos, descuentos y observaciones sin convertirlos en productos del carrito.

### `store()`

`store()` persiste el carrito mediante un sobre versionado que contiene productos y metadatos.

Conceptualmente:

```php
[
    'version' => 2,
    'content' => ...,
    'metadata' => ...,
]
```

La versión actual puede leer los registros históricos que contenían solamente la colección de productos.

### `restore()`

`restore()` recupera el carrito almacenado.

Los productos restaurados se superponen según las reglas existentes de identidad y los metadatos almacenados se agregan a los metadatos actuales.

Después de una restauración correcta, el registro persistido es consumido.

Si se desea reemplazar completamente el carrito actual antes de restaurar otro:

```php
Cart::destroy();
Cart::restore($identifier);
```

### `merge()`

`merge()` incorpora un carrito almacenado al carrito actual.

Los productos compatibles acumulan cantidades y los metadatos se agregan.

Cuando una identidad cambia durante la fusión, los descuentos asociados se adaptan al `rowId` de la línea sobreviviente.

`merge()` no consume el registro persistido.

Por tanto, ejecutar repetidamente el mismo `merge()` vuelve a incorporar sus cantidades y metadatos.

### `destroy()`

```php
Cart::destroy();
```

elimina tanto:

```text
cart.<instancia>
```

como:

```text
cart_metadata.<instancia>
```

para la instancia activa.

---

## Consideraciones al actualizar

### `rowId`

Las identidades actuales incluyen:

```text
código/id + options + alícuota
```

Por esta razón, un `rowId` generado por versiones anteriores puede diferir del que produciría actualmente una incorporación equivalente.

Las aplicaciones consumidoras no deben generar, reconstruir ni predecir un `rowId`.

Debe utilizarse el valor devuelto por el paquete.

### `subtotal()`

`subtotal()` representa actualmente la base final después de ajustes e incluye los costos ITEM.

Por tanto, código consumidor antiguo que reconstruya manualmente el documento debe revisar esta semántica.

### Costos y descuentos

No realizar:

```php
$total = Cart::total() + Cart::totalCost();
```

ni:

```php
$total = Cart::total() - Cart::totalDiscount();
```

Los ajustes ya están incorporados en `total()`.

`totalCost()` y `totalDiscount()` permiten consultar los componentes de la liquidación, no volver a aplicarlos.

### `content()` frente a `summary()`

`content()` representa las líneas comerciales originales.

`summary()` representa la liquidación fiscal final después de:

```text
PRORATED
descuentos
ITEM
IVA
propina
costos legacy
```

Para generar una factura con ajustes debe utilizarse `summary()`.

### Snapshots

Los snapshots actuales incluyen productos y metadatos.

La versión actual puede restaurar snapshots antiguos que contenían únicamente productos.

Las versiones antiguas del paquete no conocen la estructura versionada actual.

---

## Escenario completo de referencia

Suponga:

```text
Producto A
Base original: 100.00
Alícuota: GENERAL

Producto B
Base original: 50.00
Alícuota: EXEMPT

Flete PRORATED: 15.00

Instalación ITEM: 20.00
Alícuota: GENERAL

Descuento de línea sobre A: 5%

Descuento general: 10%

Propina: 5.00
```

La distribución y descuentos producen:

| Concepto              | Producto A | Producto B | Instalación ITEM |
| --------------------- | ---------: | ---------: | ---------------: |
| Base original         |     100.00 |      50.00 |            20.00 |
| Flete PRORATED        |      10.00 |       5.00 |             0.00 |
| Descuento de línea 5% |       5.50 |       0.00 |             0.00 |
| Descuento general 10% |      10.45 |       5.50 |             0.00 |
| Base final            |      94.05 |      49.50 |            20.00 |

Con driver HKA:

```text
Base GENERAL:
94.05 + 20.00 = 114.05

Base EXEMPT:
49.50

Subtotal:
114.05 + 49.50 = 163.55

IVA GENERAL 16%:
114.05 × 16% = 18.248
→ 18.25

Propina:
5.00

Total:
163.55 + 18.25 + 5.00
= 186.80
```

Por tanto:

```text
Subtotal          163.55
IVA                18.25
Propina              5.00
-------------------------
Total              186.80
```

Los costos registrados suman:

```text
Flete        15.00
Instalación  20.00
Propina       5.00
------------------
Total costos 40.00
```

El descuento efectivo total es:

```text
21.45
```

Estos valores son componentes informativos de la misma liquidación y no deben volver a agregarse o restarse del total.

---

## Resumen de uso

Para costos:

```php
Cart::addCost('freight', 15, Cart::COST_PRORATED);
Cart::addCost('installation', 20, Cart::COST_ITEM, 0, 'Instalación');
Cart::addCost('tip', 5);
```

Para descuentos:

```php
Cart::addDiscountToItem('A001', 'percentage', 5, 'Descuento especial');
Cart::addDiscount('percentage', 10, 'Pronto pago');
```

Para observaciones:

```php
Cart::addObservation('Entregar por la tarde');
```

Para consultar la liquidación:

```php
$summary = Cart::summary();
```

Para construir una factura con ajustes, utilice principalmente:

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

`content()` sigue siendo apropiado para consultar los productos originales del carrito; `summary()` es la fuente de la liquidación fiscal final.
