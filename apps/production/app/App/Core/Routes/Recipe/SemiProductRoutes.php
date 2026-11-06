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

    // Kreator produktu [201] : krótki formularz w oknie modalnym (nazwa, grupa, surowce) — półprodukt od razu w recepturze.
    $r->addRoute('POST', '/ajax/semi-products/quick', [
        'controller' => App\Controllers\Web\Recipe\SemiProductController::class,
        'method'     => 'ajaxQuick'
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
