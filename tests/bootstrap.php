<?php
require __DIR__ . '/../vendor/autoload.php';

function app($abstract = null) {
    $app = Illuminate\Container\Container::getInstance();
    return $abstract === null ? $app : $app->make($abstract);
}
function config($key, $default = null) {
    if (is_array($key)) { app('config')->set($key); return; }
    return app('config')->get($key, $default);
}
