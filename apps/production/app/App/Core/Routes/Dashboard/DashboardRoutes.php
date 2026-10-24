<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/dashboard', [
        'controller' => App\Controllers\Web\Dashboard\DashboardController::class,
        'method'     => 'index'
    ]);
    $r->addRoute('GET', '/dashboard/results', [
        'controller' => App\Controllers\Web\Dashboard\DashboardController::class,
        'method'     => 'results'
    ]);
    $r->addRoute('GET', '/dashboard/groups', [
        'controller' => App\Controllers\Web\Dashboard\DashboardController::class,
        'method'     => 'groups'
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
    $r->addRoute('GET', '/ajax/growth/sections/{id:\d+}', [
        'controller' => App\Controllers\Web\Dashboard\DashboardController::class,
        'method'     => 'growthGroup'
    ]);
    $r->addRoute('POST', '/ajax/growth/actions', [
        'controller' => App\Controllers\Web\Dashboard\DashboardController::class,
        'method'     => 'saveGrowth'
    ]);
    $r->addRoute('PATCH', '/ajax/growth/actions/{id:\d+}', [
        'controller' => App\Controllers\Web\Dashboard\DashboardController::class,
        'method'     => 'saveGrowth'
    ]);
};
