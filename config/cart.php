<?php
return [

    /*
    |--------------------------------------------------------------------------
    | Default aliquot
    |--------------------------------------------------------------------------
    |
    | This default tax rate will be used when you make a class implement the
    | Taxable interface and use the HasTax trait.
    |
    */

    'default_aliquot' => 0,
    
    /*
    |--------------------------------------------------------------------------
    | Aliquot values
    |--------------------------------------------------------------------------
    |
    | Available tax rate values will be used when you make a class implement the
    | Taxable interface and use the HasTax trait.
    |
    */

    'taxes' => [
        '0' => [
            'name' => 'GENERAL',
            'value' => 16.00
        ],
        '1' => [
            'name' => 'EXEMPT',
            'value' => 0.00
        ],
        '2' => [
            'name' => 'REDUCED',
            'value' => 8.00
        ],
        '3' => [
            'name' => 'LUXURY',
            'value' => 31.00
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Shopping cart database settings
    |--------------------------------------------------------------------------
    |
    | Here you can set the connection that the shopping cart should use when
    | storing and restoring a cart.
    |
    */

    'database' => [

        'connection' => env('DB_CONNECTION', 'mysql'),

        'table' => 'shopping_cart',

    ],

    /*
    |--------------------------------------------------------------------------
    | Destroy the cart on user logout
    |--------------------------------------------------------------------------
    |
    | When this option is set to 'true' the cart will automatically
    | destroy all cart instances when the user logs out.
    |
    */

    'destroy_on_logout' => false,

    /*
    |--------------------------------------------------------------------------
    | Default number format
    |--------------------------------------------------------------------------
    |
    | This defaults will be used for the formated numbers if you don't
    | set them in the method call.
    |
    */

    'format' => [

        'decimals' => 2,

        'decimal_point' => '.',

        'thousand_separator' => ''

    ],

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | Controller for price calculations in products.
    | Supported drivers:
    |   GENERAL :
    |       55.866 = 55.87 | 55.865 = 55.87 | 55.864 = 55.86
    |    
    |   HKA :
    |       55.866 = 55.87 | 55.865 = 55.87 | 55.864 = 55.86
    |   
    |   PNP :
    |       55.866 = 55.86 | 55.865 = 55.86 | 55.864 = 55.86
    */

    'driver' => 'HKA',

];