<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/products/all', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'showAllProducts'
    ]);

    $r->addRoute('GET', '/products/new', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'newProduct'
    ]);

    $r->addRoute('POST', '/products/new', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'newProduct'
    ]);

    $r->addRoute('GET', '/product-categories/{idCategory:\d+}/products/new', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'newProduct'
    ]);

    $r->addRoute('POST', '/product-categories/{idCategory:\d+}/products/new', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'newProduct'
    ]);

    $r->addRoute('GET', '/products/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'editProduct'
    ]);

    $r->addRoute('POST', '/products/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'editProduct'
    ]);

    $r->addRoute('PATCH', '/ajax/products/{id:\d+}', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'ajaxUpdateProduct'
    ]);


    $r->addRoute('GET', '/products/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'deleteProduct'
    ]);


    $r->addRoute('POST', '/products/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'deleteProduct'
    ]);


    $r->addRoute('GET', '/product-categories/{idCategory:\d+}/products', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'filterByCategory'
    ]);


    $r->addRoute('GET', '/products/export', [
        'controller' => App\Controllers\Web\Product\ProductExportController::class,
        'method'     => 'index'
    ]);

    $r->addRoute('POST', '/ajax/products/export', [
        'controller' => App\Controllers\Web\Product\ProductExportController::class,
        'method'     => 'export'
    ]);
};
