<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/product-categories', [
        'controller' => App\Controllers\Web\Product\CategoryController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('PATCH', '/ajax/product-categories/{id:\d+}', [
        'controller' => App\Controllers\Web\Product\CategoryController::class,
        'method'     => 'ajaxUpdateCategory'
    ]);

    $r->addRoute('POST', '/ajax/product-categories', [
        'controller' => App\Controllers\Web\Product\CategoryController::class,
        'method'     => 'ajaxCreateCategory'
    ]);
};
