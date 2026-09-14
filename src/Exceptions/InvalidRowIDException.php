<?php
namespace JeleDev\Shoppingcart\Exceptions;

use RuntimeException;

/**
 * Indica que no se encontró una línea para el identificador solicitado.
 *
 * Conserva su nombre histórico, pero también se utiliza cuando no existe un
 * código de producto. getByRowId() exige la clave interna; get() puede intentar
 * después una búsqueda por código. La ambigüedad usa otra excepción.
 *
 * @see \JeleDev\Shoppingcart\Cart::get()
 * @see AmbiguousItemException
 */
class InvalidRowIDException extends RuntimeException {}