<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    
    $r->addRoute('GET', '/test-products', [
        'controller' => App\Controllers\Web\Product\TestProductController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/test-products/new', [
        'controller' => App\Controllers\Web\Product\TestProductController::class,
        'method'     => 'newProduct'
    ]);

    $r->addRoute('POST', '/test-products/new', [
        'controller' => App\Controllers\Web\Product\TestProductController::class,
        'method'     => 'newProduct'
    ]);

    $r->addRoute('GET', '/test-products/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Product\TestProductController::class,
        'method'     => 'editProduct'
    ]);

    $r->addRoute('POST', '/test-products/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Product\TestProductController::class,
        'method'     => 'editProduct'
    ]);

    $r->addRoute('PATCH', '/ajax/test-products/{id:\d+}', [
        'controller' => App\Controllers\Web\Product\TestProductController::class,
        'method'     => 'ajaxEditProduct'
    ]);




    $r->addRoute('GET', '/test-products/{idProduct:\d+}/changelogs', [
        'controller' => App\Controllers\Web\Product\TestProductChangelogController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/test-products/{idProduct:\d+}/changelogs/new', [
        'controller' => App\Controllers\Web\Product\TestProductChangelogController::class,
        'method'     => 'newLog'
    ]);

    $r->addRoute('POST', '/test-products/{idProduct:\d+}/changelogs/new', [
        'controller' => App\Controllers\Web\Product\TestProductChangelogController::class,
        'method'     => 'newLog'
    ]);

    $r->addRoute('GET', '/test-products/{idProduct:\d+}/changelogs/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Product\TestProductChangelogController::class,
        'method'     => 'editLog'
    ]);

    $r->addRoute('POST', '/test-products/{idProduct:\d+}/changelogs/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Product\TestProductChangelogController::class,
        'method'     => 'editLog'
    ]);

    $r->addRoute('GET', '/test-products/{idProduct:\d+}/changelogs/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Product\TestProductChangelogController::class,
        'method'     => 'deleteLog'
    ]);


    $r->addRoute('POST', '/test-products/{idProduct:\d+}/changelogs/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Product\TestProductChangelogController::class,
        'method'     => 'deleteLog'
    ]);



    $r->addRoute('GET', '/test-product-stages', [
        'controller' => App\Controllers\Web\Product\TestProductStageController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/test-product-stages/new', [
        'controller' => App\Controllers\Web\Product\TestProductStageController::class,
        'method'     => 'newStage'
    ]);

    $r->addRoute('POST', '/test-product-stages/new', [
        'controller' => App\Controllers\Web\Product\TestProductStageController::class,
        'method'     => 'newStage'
    ]);

    $r->addRoute('GET', '/test-product-stages/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Product\TestProductStageController::class,
        'method'     => 'editStage'
    ]);

    $r->addRoute('POST', '/test-product-stages/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Product\TestProductStageController::class,
        'method'     => 'editStage'
    ]);

    $r->addRoute('GET', '/test-product-stages/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Product\TestProductStageController::class,
        'method'     => 'deleteStage'
    ]);


    $r->addRoute('POST', '/test-product-stages/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Product\TestProductStageController::class,
        'method'     => 'deleteStage'
    ]);


    $r->addRoute('PATCH', '/ajax/test-product-stages/prio', [
        'controller' => App\Controllers\Web\Product\TestProductStageController::class,
        'method'     => 'ajaxUpdatePriority'
    ]);

};
