<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {
    $c = App\Controllers\Web\Logistics\LogisticsController::class;

    $r->addRoute('GET', '/logistics', ['controller' => $c, 'method' => 'day']);
    $r->addRoute('GET', '/logistics/vehicles', ['controller' => $c, 'method' => 'vehicles']);
    $r->addRoute('GET', '/logistics/loading-list', ['controller' => $c, 'method' => 'loadingList']);
    $r->addRoute('PUT', '/ajax/logistics/assignments', ['controller' => $c, 'method' => 'assign']);
    $r->addRoute('POST', '/ajax/logistics/vehicles', ['controller' => $c, 'method' => 'addVehicle']);
    $r->addRoute('PUT', '/ajax/logistics/vehicles/{id:\d+}', ['controller' => $c, 'method' => 'saveVehicle']);
    $r->addRoute('PATCH', '/ajax/logistics/products/{id:\d+}/pallet', ['controller' => $c, 'method' => 'savePalletData']);
};
