<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/materials/all', [
        'controller' => App\Controllers\Web\Material\MaterialController::class,
        'method'     => 'listAll'
    ]);

    $r->addRoute('GET', '/materials/new', [
        'controller' => App\Controllers\Web\Material\MaterialController::class,
        'method'     => 'newMaterial'
    ]);
    $r->addRoute('GET', '/material-categories/{idCategory:\d+}/materials/new', [
        'controller' => App\Controllers\Web\Material\MaterialController::class,
        'method'     => 'newMaterial'
    ]);

    $r->addRoute('POST', '/material-categories/{idCategory:\d+}/materials/new', [
        'controller' => App\Controllers\Web\Material\MaterialController::class,
        'method'     => 'newMaterial'
    ]);

    $r->addRoute('POST', '/materials/new', [
        'controller' => App\Controllers\Web\Material\MaterialController::class,
        'method'     => 'newMaterial'
    ]);


    $r->addRoute('PATCH', '/ajax/materials/{id:\d+}/toggle-package', [
        'controller' => App\Controllers\Web\Material\MaterialController::class,
        'method'     => 'ajaxTogglePackagePart'
    ]);

    $r->addRoute('GET', '/materials/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Material\MaterialController::class,
        'method'     => 'editMaterial'
    ]);

    $r->addRoute('POST', '/materials/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Material\MaterialController::class,
        'method'     => 'editMaterial'
    ]);


    $r->addRoute('GET', '/materials/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Material\MaterialController::class,
        'method'     => 'deleteMaterial'
    ]);


    $r->addRoute('POST', '/materials/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Material\MaterialController::class,
        'method'     => 'deleteMaterial'
    ]);


    $r->addRoute('GET', '/material-categories/{idCategory:\d+}/materials', ['redirect' => '/materials/all?category={idCategory}']);

    $r->addRoute('GET', '/materials/export', [
        'controller' => App\Controllers\Web\Material\MaterialExportController::class,
        'method'     => 'index'
    ]);

    $r->addRoute('POST', '/ajax/materials/export', [
        'controller' => App\Controllers\Web\Material\MaterialExportController::class,
        'method'     => 'export'
    ]);

};
