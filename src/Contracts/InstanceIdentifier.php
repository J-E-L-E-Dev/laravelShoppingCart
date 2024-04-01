<?php
namespace JeleDev\Shoppingcart\Contracts;

interface InstanceIdentifier
{
    /**
     * Get the unique identifier to load the Cart from.
     *
     * @return int|string
     */
    public function getInstanceIdentifier($options = null);
}
