<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/production-employees', [
        'controller' => App\Controllers\Web\Employee\ProductionEmployeeController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/production-employees/new', [
        'controller' => App\Controllers\Web\Employee\ProductionEmployeeController::class,
        'method'     => 'newEmployee'
    ]);

    $r->addRoute('POST', '/production-employees/new', [
        'controller' => App\Controllers\Web\Employee\ProductionEmployeeController::class,
        'method'     => 'newEmployee'
    ]);

    $r->addRoute('GET', '/production-employees/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Employee\ProductionEmployeeController::class,
        'method'     => 'editEmployee'
    ]);

    $r->addRoute('POST', '/production-employees/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Employee\ProductionEmployeeController::class,
        'method'     => 'editEmployee'
    ]);

    $r->addRoute('GET', '/production-employees/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Employee\ProductionEmployeeController::class,
        'method'     => 'deleteEmployee'
    ]);

    $r->addRoute('POST', '/production-employees/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Employee\ProductionEmployeeController::class,
        'method'     => 'deleteEmployee'
    ]);




};
