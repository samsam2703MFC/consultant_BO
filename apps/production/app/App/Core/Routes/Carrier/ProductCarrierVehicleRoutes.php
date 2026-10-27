<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/product-carriers/{idCarrier:\d+}/vehicles', [
        'controller' => App\Controllers\Web\Carrier\ProductCarrierVehicleController::class,
        'method'     => 'overview'
    ]);

    // Nowy pojazd : jeden formularz (Logistyka → Pojazdy i pojemność), przewoźnik wybrany z adresu
    $r->addRoute('GET', '/product-carriers/{idCarrier:\d+}/vehicles/new', ['redirect' => '/logistics/vehicles?carrier={idCarrier}']);

    $r->addRoute('GET', '/product-carrier-vehicles/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Carrier\ProductCarrierVehicleController::class,
        'method'     => 'editVehicle'
    ]);

    $r->addRoute('POST', '/product-carrier-vehicles/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Carrier\ProductCarrierVehicleController::class,
        'method'     => 'editVehicle'
    ]);

    $r->addRoute('GET', '/product-carrier-vehicles/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Carrier\ProductCarrierVehicleController::class,
        'method'     => 'deleteVehicle'
    ]);

    $r->addRoute('POST', '/product-carrier-vehicles/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Carrier\ProductCarrierVehicleController::class,
        'method'     => 'deleteVehicle'
    ]);

};