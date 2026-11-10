<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/compositions', [
        'controller' => App\Controllers\Web\Recipe\CompositionController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/compositions/new', [
        'controller' => App\Controllers\Web\Recipe\CompositionController::class,
        'method'     => 'newComposition'
    ]);

    $r->addRoute('POST', '/compositions/new', [
        'controller' => App\Controllers\Web\Recipe\CompositionController::class,
        'method'     => 'newComposition'
    ]);

    $r->addRoute('GET', '/compositions/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Recipe\CompositionController::class,
        'method'     => 'editComposition'
    ]);

    $r->addRoute('POST', '/compositions/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Recipe\CompositionController::class,
        'method'     => 'editComposition'
    ]);

    $r->addRoute('GET', '/compositions/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Recipe\CompositionController::class,
        'method'     => 'deleteComposition'
    ]);

    $r->addRoute('POST', '/compositions/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Recipe\CompositionController::class,
        'method'     => 'deleteComposition'
    ]);

    $r->addRoute('GET', '/compositions/{id:\d+}/calculation', [
        'controller' => App\Controllers\Web\Recipe\CompositionController::class,
        'method'     => 'calculation'
    ]);

    // Kroki przygotowania : jedna strona dla wszystkich typów receptur (/recipes/{id}/preparation-steps)
    $r->addRoute('GET', '/compositions/{id:\d+}/preparation-steps', ['redirect' => '/recipes/{id}/preparation-steps']);
};
