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
    // Raport miesięczny zarządu : rentowność, marża, reklamacje (strona, PDF PL / FR, e-mail)
    $r->addRoute('GET', '/dashboard/monthly', [
        'controller' => App\Controllers\Web\Dashboard\MonthlyReportController::class,
        'method'     => 'index'
    ]);
    $r->addRoute('GET', '/dashboard/monthly/pdf', [
        'controller' => App\Controllers\Web\Dashboard\MonthlyReportController::class,
        'method'     => 'pdf'
    ]);
    $r->addRoute('POST', '/dashboard/monthly/send', [
        'controller' => App\Controllers\Web\Dashboard\MonthlyReportController::class,
        'method'     => 'send'
    ]);
    $r->addRoute('PUT', '/dashboard/monthly/settings', [
        'controller' => App\Controllers\Web\Dashboard\MonthlyReportController::class,
        'method'     => 'saveSettings'
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
