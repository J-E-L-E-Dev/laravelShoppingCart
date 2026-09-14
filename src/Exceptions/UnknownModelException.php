<?php
namespace JeleDev\Shoppingcart\Exceptions;

use RuntimeException;

/**
 * Indica que Cart::associate() recibió un nombre de clase inexistente.
 *
 * La comprobación se realiza antes de localizar la línea. No implica que se
 * haya buscado el registro del modelo ni que exista un método find() válido.
 *
 * @see \JeleDev\Shoppingcart\Cart::associate()
 */
class UnknownModelException extends RuntimeException {}