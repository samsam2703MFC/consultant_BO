<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/ajax/product-carriers/{id:\d+}/vehicles', [
        'controller' => App\Controllers\Web\Carrier\ProductCarrierVehicleController::class,
        'method' => 'ajaxGetVehiclesByCarrier'
    ]);

    $r->addRoute('GET', '/product-carriers', [
        'controller' => App\Controllers\Web\Carrier\ProductCarrierController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/product-carriers/new', [
        'controller' => App\Controllers\Web\Carrier\ProductCarrierController::class,
        'method'     => 'newCarrier'
    ]);

    $r->addRoute('POST', '/product-carriers/new', [
        'controller' => App\Controllers\Web\Carrier\ProductCarrierController::class,
        'method'     => 'newCarrier'
    ]);

    $r->addRoute('GET', '/product-carriers/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Carrier\ProductCarrierController::class,
        'method'     => 'editCarrier'
    ]);

    $r->addRoute('POST', '/product-carriers/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Carrier\ProductCarrierController::class,
        'method'     => 'editCarrier'
    ]);

    $r->addRoute('GET', '/product-carriers/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Carrier\ProductCarrierController::class,
        'method'     => 'deleteCarrier'
    ]);

    $r->addRoute('POST', '/product-carriers/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Carrier\ProductCarrierController::class,
        'method'     => 'deleteCarrier'
    ]);

};