<?php
namespace JeleDev\Shoppingcart\Contracts;

/**
 * Permite que un objeto del consumidor identifique snapshots de carrito.
 *
 * Cart::store(), restore() y merge() resuelven el objeto mediante este contrato.
 * La clave se combina con el nombre de instancia en base de datos; no representa
 * un rowId ni el código de un producto.
 */
interface InstanceIdentifier
{
    /**
     * Debe devolver una clave estable para guardar o recuperar un snapshot.
     *
     * Las operaciones actuales de Cart invocan este método sin argumentos.
     *
     * @param mixed $options Contexto opcional del consumidor; Cart no lo suministra.
     * @return int|string Identificador que se almacenará en la columna identifier.
     */
    public function getInstanceIdentifier($options = null);
}
