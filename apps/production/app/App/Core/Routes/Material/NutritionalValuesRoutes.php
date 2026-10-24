<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/materials/nutritional-values', [
        'controller' => App\Controllers\Web\Material\NutritionalValuesController::class,
        'method'     => 'overview',
    ]);

    $r->addRoute('PATCH', '/ajax/materials/{id:\d+}/nutritional-values', [
        'controller' => App\Controllers\Web\Material\NutritionalValuesController::class,
        'method'     => 'ajaxUpdate',
    ]);

    $r->addRoute('GET', '/ajax/materials/{id:\d+}/nutritional-values/lookup', [
        'controller' => App\Controllers\Web\Material\NutritionalValuesController::class,
        'method'     => 'lookup',
    ]);

    $r->addRoute('POST', '/ajax/materials/{id:\d+}/nutritional-values/apply', [
        'controller' => App\Controllers\Web\Material\NutritionalValuesController::class,
        'method'     => 'apply',
    ]);
};
