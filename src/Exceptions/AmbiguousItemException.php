<?php
namespace JeleDev\Shoppingcart\Exceptions;

/**
 * Señala que un código de producto coincide con varias líneas del carrito.
 *
 * La resolución por código no elige una variante arbitraria; el consumidor
 * debe repetir la operación utilizando el rowId de la línea deseada.
 * Puede propagarse desde get(), update(), remove(), associate(), setTax()
 * y addDiscountToItem(), además de getById().
 *
 * @see \JeleDev\Shoppingcart\Cart::getById()
 */
class AmbiguousItemException extends \RuntimeException {}
