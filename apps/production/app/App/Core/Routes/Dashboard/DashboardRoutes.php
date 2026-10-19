<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/dashboard', [
        'controller' => App\Controllers\Web\Dashboard\DashboardController::class,
        'method'     => 'index'
    ]);
    $r->addRoute('GET', '/today', [
        'controller' => App\Controllers\Web\Dashboard\DashboardController::class,
        'method'     => 'today'
    ]);
    $r->addRoute('GET', '/ajax/rail-counters', [
        'controller' => App\Controllers\Web\Dashboard\DashboardController::class,
        'method'     => 'ajaxCounters'
    ]);
    $r->addRoute('POST', '/ajax/profitability/targets', [
        'controller' => App\Controllers\Web\Dashboard\DashboardController::class,
        'method'     => 'saveTargets'
    ]);
    $r->addRoute('PATCH', '/ajax/profitability/sections/{id:\d+}', [
        'controller' => App\Controllers\Web\Dashboard\DashboardController::class,
        'method'     => 'saveSectionTarget'
    ]);
};
