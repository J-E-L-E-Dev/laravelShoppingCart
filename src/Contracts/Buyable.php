<?php
namespace JeleDev\Shoppingcart\Contracts;

/**
 * Contrato que permite convertir productos del consumidor en líneas de carrito.
 *
 * Cart utiliza estos tres atributos para construir o actualizar CartItem.
 * Una implementación debe aceptar las opciones como array en la creación y
 * como CartItemOptions en la actualización. La alícuota no forma parte de este
 * contrato: fromBuyable() usa la predeterminada y update conserva la existente.
 * CanBeBought ofrece una implementación opcional basada en propiedades.
 *
 * @see \JeleDev\Shoppingcart\CartItem::fromBuyable()
 * @see \JeleDev\Shoppingcart\CanBeBought
 */
interface Buyable
{
    /**
     * Debe devolver el código del producto para las opciones indicadas.
     *
     * Es un identificador de negocio, no rowId. Conviene devolver string para
     * conservar ceros iniciales; el código no puede tener representación vacía.
     *
     * @param array<array-key, mixed>|\JeleDev\Shoppingcart\CartItemOptions|null $options Opciones recibidas al crear o actualizar el producto.
     * @return int|string Código que CartItem conserva como id.
     */
    public function getBuyableIdentifier($options = null);

    /**
     * Debe proporcionar una descripción no vacía para la línea del producto.
     *
     * La descripción puede depender de las opciones; no interviene en el hash.
     *
     * @param array<array-key, mixed>|\JeleDev\Shoppingcart\CartItemOptions|null $options Opciones recibidas al crear o actualizar el producto.
     * @return string Nombre o descripción aceptable por CartItem.
     */
    public function getBuyableDescription($options = null);

    /**
     * Debe proporcionar el precio unitario original sin IVA ni ajustes documentales.
     *
     * El consumidor puede determinar el precio según opciones. CartItem exige un
     * valor numérico, finito, no negativo y dentro del límite de Money.
     *
     * @param array<array-key, mixed>|\JeleDev\Shoppingcart\CartItemOptions|null $options Opciones recibidas al crear o actualizar el producto.
     * @return int|float|numeric-string Precio unitario en unidades monetarias.
     */
    public function getBuyablePrice($options = null);
}