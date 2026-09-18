# Registro de cambios

English version: [CHANGELOG.md](CHANGELOG.md)

Todos los cambios relevantes del proyecto serán documentados en este archivo.

El proyecto sigue [Versionado Semántico](https://semver.org/).

## [3.0.0] - 2026-09-18

**v3.0.0 - Fiscal Precision and Integrity**

Versión mayor por cambios incompatibles en la semántica pública del IVA, la precisión contable y los tipos de valores de descuentos. No es una actualización patch ni minor de 2.x.

### Agregado

- `FiscalCalculator` como autoridad central interna para las estrategias fiscales y la validación del catálogo.
- Precisión monetaria configurable de 0 a 4 decimales y snapshots v3 con precisión explícita.
- `Money::compare()` para comparaciones decimales exactas de numeric-string, incluida notación científica; soporte de estos valores sin pérdida de dígitos en Money y descuentos. Los floats siguen siendo aproximados y se normalizan a 15 cifras significativas.
- Validación semántica de costos, descuentos y observaciones persistidos, compartida por sesión y snapshots.
- Validación de `fixedUnits` mediante estados enteros alcanzables a través de cambios sucesivos de precisión 0..4.
- Cobertura de atomicidad de `restore()` y `merge()` ante fallos de validación de productos, metadatos y snapshots.
- `git diff --check HEAD^ HEAD` revisa cambios confirmados en Git.

### Cambiado

- `CartItem::tax` representa el IVA de la fila completa; `taxTotal` presenta ese mismo IVA. `unitTax` y `priceTax` conservan semántica unitaria.
- GENERAL cuantiza la base completa `qty × price`, calcula IVA HALF_UP por fila y suma por alícuota.
- PNP calcula IVA sobre la base completa sin cuantizar (`raw qty × price`), trunca por fila y suma los impuestos individuales.
- HKA agrupa bases cuantizadas por alícuota, calcula IVA fiscal HALF_UP y reconcilia impuestos individuales dentro de cada alícuota. El residuo positivo va al mayor IVA provisional; el negativo se resta en orden sin bajar ninguna fila de cero. Desempates: mayor base y menor rowId lexicográfico.
- `cart.format.decimals` controla aritmética monetaria/fiscal y presentación, no sólo formato. Cuantización, reparto y reconciliación operan en unidades menores enteras; las entradas float no adquieren precisión decimal arbitraria.
- Los descuentos fijos conservan `fixedUnits`; `discount.value` conserva el tipo solicitado `int|float|numeric-string` y los strings exactos. Los cambios de precisión reescalan unidades sin reconstruir información perdida desde `value`.
- Snapshots v3 y metadatos conservan la escala. `restore()`/`merge()` validan integridad antes de incorporar datos, y `merge()` simula las acumulaciones completas antes de mutar sesión.
- El catálogo exige exactamente las claves 0, 1, 2 y 3, nombres no vacíos y únicos ignorando mayúsculas y espacios exteriores, y tasas numéricas, finitas y no negativas.

### Corregido

- Rechazo incorrecto de `fixedUnits` legítimos tras redondeos sucesivos, como `12345 → 1235 → 124` en la ruta 4 → 3 → 2; se siguen rechazando unidades históricamente imposibles.
- Pérdida de precisión al convertir numeric-string de descuentos a float y cruces artificiales de frontera al cuantizar importes.
- Aceptación de numeric-string negativos diminutos como `-1e-9999` en precios, costos y descuentos, y de porcentajes exactamente superiores a 100 que un float no distinguía.
- Acumulaciones de cantidades inválidas en `add()`, `addCartItem()`, `merge()` y fusiones de `update()`, detectadas antes de modificar productos, descuentos o emitir eventos de éxito.
- Aceptación de productos o metadatos corruptos por `restore()`/`merge()`, y mutaciones parciales cuando el destino era inválido.
- Omisión silenciosa de líneas con alícuotas inválidas; los catálogos incompletos y alícuotas desconocidas en snapshots provocan excepciones controladas.
- Impuestos individuales negativos durante reconciliación HKA, recálculo PNP sobre bases agrupadas y cálculo GENERAL mediante IVA unitario multiplicado por cantidad.

### Compatibilidad

- Se mantienen Laravel/Illuminate 10, 11 y 12 y PHP mínimo 8.1; Laravel 11/12 requieren PHP >=8.2 según sus dependencias.
- La persistencia del carrito en base de datos es opcional. El carrito activo, sus productos y los metadatos de ajustes funcionan mediante la sesión configurada de Laravel; la tabla `shopping_cart` sólo es necesaria al utilizar `store()`, `restore()` o `merge()`.
- Si la aplicación utiliza `SESSION_DRIVER=database`, la tabla de sesiones requerida por Laravel es independiente de la tabla opcional `shopping_cart` del paquete.
- Los snapshots legacy y v2 válidos siguen pudiendo restaurarse; v2 conserva su precisión histórica de dos decimales y los rowId históricos no se regeneran durante `restore()`.
- Los snapshots v3 incorporan precisión explícita y no deben ser consumidos por versiones antiguas del paquete.
- El catálogo debe contener exactamente 0/1/2/3. Configuraciones que eliminen categorías o agreguen claves fiscales adicionales ya no son válidas; `name` y `value` siguen siendo configurables dentro de las reglas indicadas.

### Notas de actualización

Revisar integraciones 2.x que calculaban:

```php
// Antes: ahora multiplicaría el IVA de la fila una segunda vez.
$totalTax = $item->tax * $item->qty;
// En 3.x:
$totalTax = $item->tax;
```

`taxTotal` ya corresponde a la fila; no volver a multiplicarlo por cantidad. Revisar los totales esperados GENERAL/PNP/HKA, la precisión configurada y `cart.taxes`. Usar `summary()` para liquidación fiscal con costos y descuentos.

`$discount['value']` puede ser string si se suministró un numeric-string: no asumir `is_float($discount['value']) === true` ni exigir float mediante tipos estrictos sin conversión explícita. Esa conversión puede perder precisión; conservar el valor original para decisiones monetarias exactas.

La tabla `shopping_cart` no forma parte de la instalación obligatoria del paquete. Sólo se necesita para la persistencia explícita mediante `store()`, `restore()` o `merge()`. `restore()` incorpora el snapshot almacenado a la sesión activa y después elimina ese registro persistido; `merge()` incorpora sus productos y metadatos sin consumir el snapshot, por lo que puede volver a utilizarse posteriormente.

Consultar la [guía de actualización de 2.x a 3.x](docs/adjustments.es.md#actualización-desde-2x-a-3x) antes de desplegar.

## [2.0.1] - 2026-09-16

### Corregido

- Corregidas las restricciones de compatibilidad de PHP e Illuminate declaradas por el paquete.
- Eliminadas las declaraciones obsoletas de compatibilidad con Laravel 7, 8 y 9.
- Alineadas las dependencias Illuminate de ejecución y desarrollo con las versiones realmente soportadas por el paquete.

### Agregado

- Compatibilidad oficial con Laravel / Illuminate 12.
- Pruebas automatizadas de compatibilidad para las versiones soportadas de PHP e Illuminate mediante GitHub Actions.

### Compatibilidad

El paquete se prueba ahora automáticamente con la siguiente matriz de compatibilidad:

- Laravel / Illuminate 10:
  - PHP 8.1
  - PHP 8.2
  - PHP 8.3
  - PHP 8.4
- Laravel / Illuminate 11:
  - PHP 8.2
  - PHP 8.3
  - PHP 8.4
- Laravel / Illuminate 12:
  - PHP 8.2
  - PHP 8.3
  - PHP 8.4

PHP 8.1 se mantiene como requisito mínimo del paquete. Laravel / Illuminate 11 y 12 requieren PHP 8.2 o superior.

## [2.0.0] - 2026-09-15

### Agregado

- Costos adicionales persistentes en modalidades ITEM y PRORATED.
- Soporte de propinas con observaciones estructuradas.
- Descuentos por línea y descuentos generales del documento.
- Descuentos de tipo fijo y porcentual.
- `Cart::summary()` como fuente de la liquidación fiscal final.
- Líneas fiscales estructuradas para productos y costos ITEM.
- Bases fiscales finales agrupadas por alícuota.
- Resolución segura de líneas mediante `rowId` o código de producto inequívoco.
- Métodos de búsqueda `getByRowId()` y `getById()`.
- Persistencia de metadatos de ajustes para costos, descuentos y observaciones.
- Snapshots versionados que contienen productos y metadatos de ajustes.
- Distribución proporcional exacta de costos y descuentos utilizando centavos enteros.
- Cobertura ampliada de pruebas para los drivers fiscales.

### Cambiado

- La identidad de una línea ahora está formada por código/id del producto, opciones y alícuota.
- Los nuevos valores de `rowId` incluyen la alícuota como parte de la identidad.
- `add()` acumula cantidad solamente cuando coincide la identidad completa de la línea.
- `update()` es la operación recomendada para modificar una línea existente.
- `subtotal()` ahora representa la base final ajustada e incluye costos ITEM.
- GENERAL calcula el impuesto de cada línea fiscal final y posteriormente suma los impuestos por alícuota.
- HKA agrupa las bases finales por alícuota antes de calcular el impuesto.
- PNP trunca las bases de las líneas de productos, las agrupa por alícuota y trunca el impuesto resultante.
- Los costos ITEM participan en la liquidación fiscal según el driver configurado.
- Los costos PRORATED se incorporan a las bases de los productos antes de descuentos e impuestos.
- Los carritos almacenados utilizan ahora una estructura versionada que contiene contenido y metadatos.
- `destroy()` elimina también los metadatos de ajustes.

### Compatibilidad

- Las aplicaciones no deben generar, reconstruir ni predecir valores de `rowId`.
- La búsqueda mediante código de producto sólo puede utilizarse cuando el código identifica una única línea; los códigos ambiguos requieren `rowId`.
- Las llamadas existentes a `addCost($name, $price)` sin modalidad conservan su comportamiento legacy.
- La versión actual puede restaurar carritos antiguos que contengan únicamente colecciones de productos.
- Las versiones anteriores del paquete no entienden la nueva estructura versionada de snapshots.
- Las aplicaciones que generen facturas con ajustes deben utilizar `Cart::summary()` en lugar de reconstruir las bases finales desde `Cart::content()`.
- `totalCost()` y `totalDiscount()` son componentes informativos y no deben volver a aplicarse sobre `Cart::total()`.

### Notas de actualización

Esta es una versión mayor porque algunos comportamientos existentes pueden requerir cambios en las aplicaciones consumidoras.

Antes de actualizar desde 1.x:

- Deje de generar o depender de hashes `rowId` calculados por la aplicación.
- Revise el código que utiliza `subtotal()`, ya que ahora incluye los ajustes aplicados y costos ITEM.
- Utilice `summary()` para generar documentos fiscales que contengan costos o descuentos.
- No sume `totalCost()` a `total()` ni reste `totalDiscount()` de éste.
- Revise los totales fiscales si la aplicación depende del comportamiento de redondeo de GENERAL, HKA o PNP.
