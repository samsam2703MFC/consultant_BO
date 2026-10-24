<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/material-suppliers', [
        'controller' => App\Controllers\Web\Material\Supplier\MaterialSupplierController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/material-suppliers/new', [
        'controller' => App\Controllers\Web\Material\Supplier\MaterialSupplierController::class,
        'method'     => 'newSupplier'
    ]);

    $r->addRoute('POST', '/material-suppliers/new', [
        'controller' => App\Controllers\Web\Material\Supplier\MaterialSupplierController::class,
        'method'     => 'newSupplier'
    ]);

    $r->addRoute('GET', '/material-suppliers/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Material\Supplier\MaterialSupplierController::class,
        'method'     => 'editSupplier'
    ]);

    $r->addRoute('POST', '/material-suppliers/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Material\Supplier\MaterialSupplierController::class,
        'method'     => 'editSupplier'
    ]);

    $r->addRoute('GET', '/material-suppliers/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Material\Supplier\MaterialSupplierController::class,
        'method'     => 'deleteSupplier'
    ]);

    $r->addRoute('POST', '/material-suppliers/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Material\Supplier\MaterialSupplierController::class,
        'method'     => 'deleteSupplier'
    ]);




};
