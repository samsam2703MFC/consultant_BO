<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/production-sections/profitability', [
        'controller' => App\Controllers\Web\Production\ProductionSectionController::class,
        'method' => 'profitability'
    ]);

    $r->addRoute('GET', '/production-sections/{idSection:\d+}/profitability/details', [
        'controller' => App\Controllers\Web\Production\ProductionSectionController::class,
        'method' => 'profitabilityDetails'
    ]);


};
