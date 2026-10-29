<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/semi-products', [
        'controller' => App\Controllers\Web\Recipe\SemiProductController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/semi-products/new', [
        'controller' => App\Controllers\Web\Recipe\SemiProductController::class,
        'method'     => 'newSemiProduct'
    ]);

    $r->addRoute('POST', '/semi-products/new', [
        'controller' => App\Controllers\Web\Recipe\SemiProductController::class,
        'method'     => 'newSemiProduct'
    ]);

    $r->addRoute('GET', '/semi-products/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Recipe\SemiProductController::class,
        'method'     => 'editSemiProduct'
    ]);

    $r->addRoute('POST', '/semi-products/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Recipe\SemiProductController::class,
        'method'     => 'editSemiProduct'
    ]);

    $r->addRoute('GET', '/semi-products/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Recipe\SemiProductController::class,
        'method'     => 'deleteSemiProduct'
    ]);

    $r->addRoute('POST', '/semi-products/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Recipe\SemiProductController::class,
        'method'     => 'deleteSemiProduct'
    ]);

    $r->addRoute('GET', '/semi-products/{id:\d+}/calculation', [
        'controller' => App\Controllers\Web\Recipe\SemiProductController::class,
        'method'     => 'calculation'
    ]);


};
