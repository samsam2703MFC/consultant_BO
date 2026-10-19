<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {


    $r->addRoute('GET', '/ajax/clients/{id:\d+}/price-list', [
        'controller' => App\Controllers\Web\Product\Price\PriceListController::class,
        'method' => 'ajaxGetPriceListByClientId'
    ]);


    $r->addRoute('GET', '/ajax/clients/{idClient:\d+}/products/{idProduct:\d+}/price', [
        'controller' => App\Controllers\Web\Product\Price\PriceListController::class,
        'method' => 'ajaxGetProductPriceByClientId'
    ]);


    $r->addRoute('GET', '/price-lists/clients', [
        'controller' => App\Controllers\Web\Product\Price\PriceListController::class,
        'method' => 'clientOverview'
    ]);

    $r->addRoute('GET', '/price-lists/general', [
        'controller' => App\Controllers\Web\Product\Price\PriceListController::class,
        'method' => 'generalPriceList'
    ]);


    $r->addRoute('GET', '/clients/{id:\d+}/price-list', [
        'controller' => App\Controllers\Web\Product\Price\PriceListController::class,
        'method' => 'clientPriceList'
    ]);


    $r->addRoute('GET', '/clients/{idClient:\d+}/price-list/global-increase', [
        'controller' => App\Controllers\Web\Product\Price\PriceListController::class,
        'method' => 'raiseAllForClientsPricePanel'
    ]);

    $r->addRoute('POST', '/clients/{idClient:\d+}/price-list/global-increase', [
        'controller' => App\Controllers\Web\Product\Price\PriceListController::class,
        'method' => 'raiseAllForClientsPricePanel'
    ]);


    $r->addRoute('PATCH', '/ajax/clients/{idClient:\d+}/products/{idProduct:\d+}/price', [
        'controller' => App\Controllers\Web\Product\Price\PriceListController::class,
        'method' => 'ajaxUpdateProductPriceForClient'
    ]);

    $r->addRoute('GET', '/ajax/clients/{id:\d+}/price-list/csv', [
        'controller' => App\Controllers\Web\Product\Price\PriceListController::class,
        'method' => 'ajaxGetPriceListByClientCSV'
    ]);

    $r->addRoute('GET', '/ajax/clients/{id:\d+}/price-list/json', [
        'controller' => App\Controllers\Web\Product\Price\PriceListController::class,
        'method' => 'ajaxGetPriceListByClientJSON'
    ]);

    $r->addRoute('PATCH', '/ajax/clients/{idClient:\d+}/products/{idProduct:\d+}/status', [
        'controller' => App\Controllers\Web\Product\Price\PriceListController::class,
        'method' => 'ajaxChangeAvailabilityStatusForClient'
    ]);

    // Cennik ogólny produktów – eksport CSV
    $r->addRoute('GET', '/ajax/products/price-list/csv', [
        'controller' => App\Controllers\Web\Product\Price\PriceListController::class,
        'method' => 'ajaxExportGeneralPriceListCSV'
    ]);

    // Cennik ogólny produktów – eksport PDF
    $r->addRoute('GET', '/ajax/products/price-list/pdf', [
        'controller' => App\Controllers\Web\Product\Price\PriceListController::class,
        'method' => 'ajaxExportGeneralPriceListPDF'
    ]);

    // Cennik ogólny produktów – eksport JSON (format importu)
    $r->addRoute('GET', '/ajax/products/price-list/json', [
        'controller' => App\Controllers\Web\Product\Price\PriceListController::class,
        'method' => 'ajaxExportGeneralPriceListJSON'
    ]);
};
