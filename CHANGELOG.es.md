# Registro de cambios

English version: [CHANGELOG.md](CHANGELOG.md)

Todos los cambios relevantes del proyecto serán documentados en este archivo.

El proyecto sigue [Versionado Semántico](https://semver.org/).

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