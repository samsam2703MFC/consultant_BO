<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    // Kategorie produktów : zakładka « Kategorie » w Kartach produktów (jedna strona zamiast dwóch).
    $r->addRoute('GET', '/product-categories', ['redirect' => '/workshop/products#/categories']);

    $r->addRoute('GET', '/ajax/product-categories', [
        'controller' => App\Controllers\Web\Product\CategoryController::class,
        'method'     => 'ajaxList'
    ]);

    $r->addRoute('PATCH', '/ajax/product-categories/{id:\d+}', [
        'controller' => App\Controllers\Web\Product\CategoryController::class,
        'method'     => 'ajaxUpdateCategory'
    ]);

    // [339] Włącz / wyłącz kategorię = wszystkie jej produkty (administrator, produkcja).
    $r->addRoute('PUT', '/ajax/product-categories/{id:\d+}/active', [
        'controller' => App\Controllers\Web\Product\CategoryController::class,
        'method'     => 'ajaxSetActive'
    ]);

    $r->addRoute('POST', '/ajax/product-categories', [
        'controller' => App\Controllers\Web\Product\CategoryController::class,
        'method'     => 'ajaxCreateCategory'
    ]);
};
