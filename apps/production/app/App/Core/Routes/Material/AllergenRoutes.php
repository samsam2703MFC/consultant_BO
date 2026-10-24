<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/ajax/allergens', [
        'controller' => App\Controllers\Web\Material\AllergenController::class,
        'method'     => 'ajaxListAllergens'
    ]);

    $r->addRoute('GET', '/ajax/materials/{idMaterial:\d+}/allergens', [
        'controller' => App\Controllers\Web\Material\AllergenController::class,
        'method'     => 'ajaxGetMaterialAllergens'
    ]);

    $r->addRoute('PATCH', '/ajax/materials/{idMaterial:\d+}/allergens', [
        'controller' => App\Controllers\Web\Material\AllergenController::class,
        'method'     => 'ajaxUpdateMaterialAllergens'
    ]);

    // Opcjonalnie, jeśli frontend nie potrafi PATCH:
    $r->addRoute('POST', '/ajax/materials/{idMaterial:\d+}/allergens', [
        'controller' => App\Controllers\Web\Material\AllergenController::class,
        'method'     => 'ajaxUpdateMaterialAllergens'
    ]);
};

