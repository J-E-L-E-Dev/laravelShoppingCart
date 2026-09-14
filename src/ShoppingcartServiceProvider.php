<?php
namespace JeleDev\Shoppingcart;

use Illuminate\Auth\Events\Logout;
use Illuminate\Session\SessionManager;
use Illuminate\Support\ServiceProvider;

/**
 * Integra el carrito con el contenedor, configuración y eventos de Laravel.
 *
 * Registra el binding cart que utiliza la Facade, publica configuración y
 * migración, y conecta la limpieza de instancias con el evento Logout.
 * La declaración de alias y autodetección de la Facade está en composer.json;
 * este provider no registra explícitamente un alias PHP.
 */
class ShoppingcartServiceProvider extends ServiceProvider
{

    /**
     * Registra un binding transitorio de cart hacia Cart, no un singleton.
     *
     * Combina config/cart.php y permite publicarlo con la etiqueta config.
     * Escucha Logout y, solo si cart.destroy_on_logout está activo, elimina las
     * raíces cart y cart_metadata de sesión para todas las instancias.
     * Publica la migración con etiqueta migrations y nombre con marca temporal
     * si la clase CreateShoppingcartTable todavía no existe.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind('cart', 'JeleDev\Shoppingcart\Cart');

        $config = __DIR__ . '/../config/cart.php';
        $this->mergeConfigFrom($config, 'cart');

        $this->publishes([__DIR__ . '/../config/cart.php' => config_path('cart.php')], 'config');

        $this->app['events']->listen(Logout::class, function () {
            if ($this->app['config']->get('cart.destroy_on_logout')) {
                $this->app->make(SessionManager::class)->forget('cart');
                $this->app->make(SessionManager::class)->forget('cart_metadata');
            }
        });

        if (!class_exists('CreateShoppingcartTable')) {
            // Publica la migración con una marca temporal para la aplicación consumidora.
            $timestamp = date('Y_m_d_His', time());

            $this->publishes([
                __DIR__ . '/../database/migrations/2024_03_23_162921_create_shopping_cart_table.php' => database_path('migrations/'.$timestamp.'_create_shoppingcart_table.php'),
            ], 'migrations');
        }
    }
}
