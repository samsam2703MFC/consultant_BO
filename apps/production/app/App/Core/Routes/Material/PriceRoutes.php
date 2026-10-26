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

    // Historia cen surowca ze źródłem zmiany (audyt 2, P21)
    $r->addRoute('GET', '/ajax/materials/{idMaterial:\d+}/price-history', [
        'controller' => App\Controllers\Web\Material\PriceController::class,
        'method'     => 'ajaxPriceHistory'
    ]);

    // Uruchamia przeliczenie kosztów receptur powiązanych z surowcem
    $r->addRoute('POST', '/ajax/materials/{idMaterial:\d+}/recalculate-related-recipe-costs', [
        'controller' => App\Controllers\Web\Material\PriceController::class,
        'method'     => 'ajaxRecalculateRelatedRecipeCosts'
    ]);


};
