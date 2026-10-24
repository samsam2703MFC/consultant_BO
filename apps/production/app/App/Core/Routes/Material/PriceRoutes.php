<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {


    $r->addRoute('GET', '/materials/prices', [
        'controller' => App\Controllers\Web\Material\PriceController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('PATCH', '/ajax/materials/{idMaterial:\d+}/price', [
        'controller' => App\Controllers\Web\Material\PriceController::class,
        'method'     => 'ajaxUpdatePrice'
    ]);

    // Uruchamia przeliczenie kosztów receptur powiązanych z surowcem
    $r->addRoute('POST', '/ajax/materials/{idMaterial:\d+}/recalculate-related-recipe-costs', [
        'controller' => App\Controllers\Web\Material\PriceController::class,
        'method'     => 'ajaxRecalculateRelatedRecipeCosts'
    ]);


};
