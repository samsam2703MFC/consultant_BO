<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/aliases', [
        'controller' => App\Controllers\Web\Alias\AliasController::class,
        'method'     => 'index'
    ]);

    $r->addRoute('GET', '/materials/aliases', [
        'controller' => App\Controllers\Web\Alias\MaterialController::class,
        'method'     => 'aliases'
    ]);

    $r->addRoute('PATCH', '/ajax/materials/{id:\d+}/aliases', [
        'controller' => App\Controllers\Web\Alias\MaterialController::class,
        'method'     => 'updateAliasByElementId'
    ]);

    $r->addRoute('GET', '/allergens/aliases', [
        'controller' => App\Controllers\Web\Alias\AllergenController::class,
        'method'     => 'aliases'
    ]);

    $r->addRoute('PATCH', '/ajax/allergens/{id:\d+}/aliases', [
        'controller' => App\Controllers\Web\Alias\AllergenController::class,
        'method'     => 'updateAliasByElementId'
    ]);
};

