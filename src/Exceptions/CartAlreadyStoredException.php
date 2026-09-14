<?php
namespace JeleDev\Shoppingcart\Exceptions;

use RuntimeException;

/**
 * Indica que store() encontró un snapshot para el mismo identificador e instancia.
 *
 * El método no sobrescribe ese registro; permite al consumidor resolver la
 * colisión antes de intentar guardar nuevamente.
 *
 * @see \JeleDev\Shoppingcart\Cart::store()
 */
class CartAlreadyStoredException extends RuntimeException {}