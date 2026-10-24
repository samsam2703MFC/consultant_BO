<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    // Kategorie surowców : zakładka « Kategorie » strony « Surowce i kategorie » (/materials/all).
    $r->addRoute('GET', '/material-categories', ['redirect' => '/materials/all?tab=categories']);

    $r->addRoute('POST', '/ajax/material-categories', [
        'controller' => App\Controllers\Web\Material\CategoryController::class,
        'method'     => 'createCategory'
    ]);

    $r->addRoute('PATCH', '/ajax/material-categories/{id:\d+}', [
        'controller' => App\Controllers\Web\Material\CategoryController::class,
        'method'     => 'updateCategory'
    ]);
};
