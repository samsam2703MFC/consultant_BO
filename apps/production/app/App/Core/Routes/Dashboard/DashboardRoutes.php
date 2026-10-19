<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/dashboard', [
        'controller' => App\Controllers\Web\Dashboard\DashboardController::class,
        'method'     => 'index'
    ]);
    $r->addRoute('GET', '/ajax/rail-counters', [
        'controller' => App\Controllers\Web\Dashboard\DashboardController::class,
        'method'     => 'ajaxCounters'
    ]);
};
