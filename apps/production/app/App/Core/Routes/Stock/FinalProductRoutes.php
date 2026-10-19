<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {


    $r->addRoute('GET', '/stock', [
        'controller' => App\Controllers\Web\Stock\FinalProductStockController::class,
        'method'     => 'supplyMonitor'
    ]);

    $r->addRoute('POST', '/ajax/stock/supply-monitor', [
        'controller' => App\Controllers\Web\Stock\FinalProductStockController::class,
        'method'     => 'ajaxSupplyMonitor'
    ]);

    $r->addRoute('GET', '/products/stock/all', [
        'controller' => App\Controllers\Web\Stock\FinalProductStockController::class,
        'method'     => 'stockState'
    ]);

    $r->addRoute('GET', '/ajax/stock/stock-state', [
        'controller' => App\Controllers\Web\Document\StockDocumentController::class,
        'method'     => 'ajaxGenerateDifferenceDocument'
    ]);


};
