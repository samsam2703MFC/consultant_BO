<?php

use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/difference-documents/{id:\d+}/products', [
        'controller' => App\Controllers\Web\Document\DifferenceController::class,
        'method'     => 'productOverview'
    ]);

    $r->addRoute('PATCH', '/ajax/difference-documents/{id:\d+}/products/{idProduct:\d+}', [
        'controller' => App\Controllers\Web\Document\DifferenceController::class,
        'method'     => 'ajaxUpdateProduct'
    ]);

    $r->addRoute('GET', '/ajax/difference-documents/{id:\d+}/document', [
        'controller' => App\Controllers\Web\Document\DifferenceController::class,
        'method'     => 'ajaxGenerateDifferenceDocument'
    ]);

};
