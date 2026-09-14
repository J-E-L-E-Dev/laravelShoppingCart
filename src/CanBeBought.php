<?php
namespace JeleDev\Shoppingcart;

/**
 * Proporciona implementaciones por defecto para el contrato de producto comprable.
 *
 * Puede incorporarse en clases del consumidor que implementen Contracts\Buyable.
 * Busca atributos reales con property_exists() para descripción y precio;
 * no inspecciona los atributos dinámicos de Eloquent. Sus métodos ignoran options.
 * Si no encuentra descripción o precio devuelve null, que no constituye por sí
 * solo un producto válido para el constructor de CartItem.
 *
 * @see \JeleDev\Shoppingcart\Contracts\Buyable
 */
trait CanBeBought
{

    /**
     * Obtiene getKey() cuando existe ese método; en otro caso lee id.
     *
     * No valida ni convierte el retorno; la clase consumidora debe proporcionar
     * un código válido para CartItem.
     *
     * @param array<array-key, mixed>|\JeleDev\Shoppingcart\CartItemOptions|null $options Opciones recibidas al crear o actualizar el producto.
     * @return mixed Valor de getKey() o id, normalmente int|string.
     */
    public function getBuyableIdentifier($options = null)
    {
        return method_exists($this, 'getKey') ? $this->getKey() : $this->id;
    }

    /**
     * Busca una propiedad existente name, title o description, en ese orden.
     *
     * Devuelve la primera propiedad existente aunque su valor sea null; no intenta
     * las siguientes por tener valor vacío. Si ninguna existe retorna null.
     *
     * @param array<array-key, mixed>|\JeleDev\Shoppingcart\CartItemOptions|null $options Opciones recibidas al crear o actualizar el producto.
     * @return mixed Valor de la propiedad encontrada, normalmente string, o null.
     */
    public function getBuyableDescription($options = null)
    {
        if(property_exists($this, 'name')) return $this->name;
        if(property_exists($this, 'title')) return $this->title;
        if(property_exists($this, 'description')) return $this->description;

        return null;
    }

    /**
     * Lee la propiedad price únicamente si property_exists() la reconoce.
     *
     * No convierte el valor ni consulta atributos dinámicos; retorna null si falta.
     * El consumidor debe proporcionar un precio numérico válido para CartItem.
     *
     * @param array<array-key, mixed>|\JeleDev\Shoppingcart\CartItemOptions|null $options Opciones recibidas al crear o actualizar el producto.
     * @return mixed Valor original de price, normalmente numérico, o null.
     */
    public function getBuyablePrice($options = null)
    {
        if(property_exists($this, 'price')) return $this->price;

        return null;
    }
}
