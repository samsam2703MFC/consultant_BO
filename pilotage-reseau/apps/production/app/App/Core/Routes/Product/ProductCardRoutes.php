<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/ajax/products/{idProduct:\d+}/technical-card', [
        'controller' => App\Controllers\Web\Product\ProductCardController::class,
        'method' => 'ajaxGetProductCard'
    ]);

    // Zdjęcie produktu i 4 etapy produkcji ze zdjęciem (karta produktu → tablet, zakładka Receptury)
    $media = App\Controllers\Web\Product\ProductMediaController::class;
    $r->addRoute('GET', '/ajax/products/{id:\d+}/media', ['controller' => $media, 'method' => 'sheet']);
    $r->addRoute('PUT', '/ajax/products/{id:\d+}/media/{slot:[0-4]}', ['controller' => $media, 'method' => 'save']);
    $r->addRoute('DELETE', '/ajax/products/{id:\d+}/media/{slot:[0-4]}', ['controller' => $media, 'method' => 'deleteImage']);
    $r->addRoute('GET', '/products/{id:\d+}/media/{slot:[0-4]}', ['controller' => $media, 'method' => 'image']);

    // Krok « Ceny i zgodność » karty produktu : cena standardowa (PATCH …/standard-price), ceny klientów, PPWR, Belgia
    $commercial = App\Controllers\Web\Product\ProductCommercialController::class;
    $r->addRoute('GET', '/ajax/products/{id:\d+}/commercial', ['controller' => $commercial, 'method' => 'summary']);
    $r->addRoute('POST', '/ajax/products/{id:\d+}/commercial/copy-price', ['controller' => $commercial, 'method' => 'copyPrice']);

    $r->addRoute('GET', '/ajax/compositions/{idRecipe:\d+}/technical-card', [
        'controller' => App\Controllers\Web\Product\ProductCardController::class,
        'method' => 'ajaxGetCompositionCard'
    ]);

};
