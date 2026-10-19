<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/ajax/products/{idProduct:\d+}/technical-card', [
        'controller' => App\Controllers\Web\Product\ProductCardController::class,
        'method' => 'ajaxGetProductCard'
    ]);

    $r->addRoute('GET', '/ajax/compositions/{idRecipe:\d+}/technical-card', [
        'controller' => App\Controllers\Web\Product\ProductCardController::class,
        'method' => 'ajaxGetCompositionCard'
    ]);

};
