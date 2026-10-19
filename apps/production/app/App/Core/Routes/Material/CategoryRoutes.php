<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/material-categories', [
        'controller' => App\Controllers\Web\Material\CategoryController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('POST', '/ajax/material-categories', [
        'controller' => App\Controllers\Web\Material\CategoryController::class,
        'method'     => 'createCategory'
    ]);

    $r->addRoute('PATCH', '/ajax/material-categories/{id:\d+}', [
        'controller' => App\Controllers\Web\Material\CategoryController::class,
        'method'     => 'updateCategory'
    ]);
};
