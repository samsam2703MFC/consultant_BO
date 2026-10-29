<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {


    // /stock połączone z zakładką « Stan magazynu » Kart produktów (zamówienia klient po kliencie, rok temu, pokrycie).
    $r->addRoute('GET', '/stock', ['redirect' => '/workshop/stock']);

    $r->addRoute('GET', '/products/stock/all', ['redirect' => '/workshop/stock']);

    $r->addRoute('GET', '/ajax/stock/stock-state', [
        'controller' => App\Controllers\Web\Document\StockDocumentController::class,
        'method'     => 'ajaxGenerateDifferenceDocument'
    ]);


};
