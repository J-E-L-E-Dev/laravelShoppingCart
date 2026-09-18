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
    | decimals controls monetary/fiscal quantization AND presentation (default 2).
    | It must be an integer from 0 to 4. Money centralizes the scale 10^decimals.
    | Separators affect presentation only. Session metadata and v3 snapshots
    | store precision; legacy/v2 amounts use their historical two-decimal scale.
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
    | GENERAL: HALF_UP base, HALF_UP VAT per complete line, then sum per aliquot.
    | HKA: HALF_UP bases, group per aliquot, HALF_UP grouped VAT. Original item
    | taxes are reconciled to that grouped authority without persisting overrides.
    | PNP: truncate VAT per complete raw line, then sum; never tax grouped bases.
    | PNP subtotal bases remain truncated, but VAT uses raw qty * unit price.
    | All policies use format.decimals. Adjustments operate in integer minor units.
    */

    'driver' => 'HKA',

];
