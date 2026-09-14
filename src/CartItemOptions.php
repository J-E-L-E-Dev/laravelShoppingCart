<?php
namespace JeleDev\Shoppingcart;

use Illuminate\Support\Collection;

/**
 * Agrupa opciones originales de una línea y permite acceder a ellas como propiedades.
 *
 * Extiende Collection para que CartItem pueda serializarlas, exportarlas y
 * obtenerlas mediante all(). Participan en la identidad; cambiar opciones de
 * una línea guardada debe hacerse mediante Cart::update() para sincronizar rowId.
 *
 * @extends Collection<array-key, mixed>
 */
class CartItemOptions extends Collection
{
    /**
     * Busca una opción por clave mediante Collection::get().
     *
     * No modifica opciones ni calcula una identidad nueva.
     *
     * @param string $key Clave de la opción solicitada.
     * @return mixed Valor de la opción o null si no existe.
     */
    public function __get($key)
    {
        return $this->get($key);
    }
}