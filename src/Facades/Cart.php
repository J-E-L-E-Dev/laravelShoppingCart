<?php
namespace JeleDev\Shoppingcart\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Expone el servicio cart del contenedor mediante llamadas estáticas de Laravel.
 *
 * La Facade delega en JeleDev\Shoppingcart\Cart; no guarda un carrito separado.
 * ShoppingcartServiceProvider registra un binding transitorio, aunque Laravel
 * puede conservar la instancia resuelta por la Facade. instance() selecciona
 * sobre ese objeto el carrito que usarán las llamadas posteriores.
 *
 * @mixin \JeleDev\Shoppingcart\Cart
 * @method static \JeleDev\Shoppingcart\Cart instance(string|null $instance = null) Selecciona la instancia de sesión.
 * @method static string currentInstance() Consulta el nombre de instancia.
 * @method static \JeleDev\Shoppingcart\CartItem add(mixed $id, mixed $name = null, mixed $qty = null, mixed $price = null, mixed $aliquot = null, array $options = []) Incorpora atributos, array o Buyable.
 * @method static \JeleDev\Shoppingcart\CartItem get(int|string $rowId) Resuelve rowId o código inequívoco.
 * @method static \JeleDev\Shoppingcart\CartItem getByRowId(string $rowId) Busca solo la clave interna.
 * @method static \JeleDev\Shoppingcart\CartItem getById(int|string $id) Busca un código y rechaza ambigüedad.
 * @method static \JeleDev\Shoppingcart\CartItem|null update(int|string $rowId, mixed $qty) Actualiza atributos o cantidad.
 * @method static void remove(int|string $rowId) Elimina la línea y sus descuentos.
 * @method static void associate(int|string $rowId, string|object $model) Asocia una clase de modelo.
 * @method static void setTax(int|string $rowId, int|string|null $taxRate) Cambia la clave de alícuota.
 * @method static \Illuminate\Support\Collection<string, \JeleDev\Shoppingcart\CartItem> content() Consulta productos originales.
 * @method static int|float count() Suma cantidades de productos.
 * @method static void addCost(string $name, mixed $price = null, string|null $mode = null, int|string|null $aliquot = null, string|null $description = null, mixed $amount = null) Registra una operación de costo.
 * @method static string getCost(string $name) Suma formateada por compatibilidad.
 * @method static \Illuminate\Support\Collection costs() Consulta registros estructurados de costos.
 * @method static \Illuminate\Support\Collection costDetails(string $name) Filtra operaciones de costo por nombre.
 * @method static int|float totalCost() Consulta costos registrados, incluida propina.
 * @method static \JeleDev\Shoppingcart\Cart addDiscount(string $type, mixed $value, string $concept = '') Registra descuento general.
 * @method static \JeleDev\Shoppingcart\Cart addDiscountToItem(int|string $identifier, string $type, mixed $value, string $concept = '') Registra descuento de una línea resuelta.
 * @method static \Illuminate\Support\Collection discounts() Consulta descuentos efectivos y repartos.
 * @method static int|float totalDiscount() Suma los descuentos efectivos.
 * @method static \JeleDev\Shoppingcart\Cart addObservation(string $text) Registra texto manual.
 * @method static \Illuminate\Support\Collection observations() Consulta observaciones manuales y automáticas.
 * @method static array summary() Liquida bases finales e impuestos para facturación; estructura en CartAdjustments::summary().
 * @method static string subtotal() Presenta la base final con costos ITEM.
 * @method static array tax() Consulta IVA agrupado de la liquidación.
 * @method static string total() Presenta base final, IVA, propina y legacy.
 * @method static void store(mixed $identifier) Guarda productos y metadatos en un snapshot.
 * @method static void restore(mixed $identifier) Superpone y consume el snapshot.
 * @method static bool merge(mixed $identifier, bool $dispatchAdd = true, string $instance = 'shopping_cart') Incorpora un snapshot sin consumirlo.
 * @method static void destroy() Limpia productos y metadatos de la instancia.
 * @see \JeleDev\Shoppingcart\Cart
 * @see \JeleDev\Shoppingcart\CartAdjustments::summary()
 */
class Cart extends Facade {
    /**
     * Indica el binding del contenedor que Laravel debe resolver para esta Facade.
     *
     * @return string Clave cart registrada por ShoppingcartServiceProvider.
     */
    protected static function getFacadeAccessor()
    {
        return 'cart';
    }
}
