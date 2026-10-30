<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/products/price-structure', ['redirect' => '/workshop/products#/?erp=all']);

    $r->addRoute('GET', '/finances/clients', ['controller' => App\Controllers\Web\Finances\ClientVolumeController::class, 'method' => 'index']);
    $r->addRoute('GET', '/finances/cost-analysis', [
        'controller' => App\Controllers\Web\Finances\Product\CostAnalysisController::class,
        'method' => 'overview'
    ]);

    $r->addRoute('GET', '/finances/cost-analysis/section', [
        'controller' => App\Controllers\Web\Finances\Product\CostAnalysisSectionController::class,
        'method' => 'overview'
    ]);

    $r->addRoute('GET', '/finances/product-margin', [
        'controller' => App\Controllers\Web\Finances\Product\ProductMarginController::class,
        'method' => 'overview'
    ]);

    $r->addRoute('GET', '/ajax/product-margin/foodcost-breakdown', [
        'controller' => App\Controllers\Web\Finances\Product\ProductMarginController::class,
        'method' => 'ajaxFoodcostBreakdown'
    ]);

    $r->addRoute('GET', '/finances/category-margin', [
        'controller' => App\Controllers\Web\Finances\Product\CategoryMarginController::class,
        'method' => 'overview'
    ]);

    $r->addRoute('POST', '/ajax/category-margin/recalculate', [
        'controller' => App\Controllers\Web\Finances\Product\CategoryMarginController::class,
        'method' => 'recalculate'
    ]);

    $r->addRoute('POST', '/ajax/category-margin/product-action', [
        'controller' => App\Controllers\Web\Finances\Product\CategoryMarginController::class,
        'method' => 'productAction'
    ]);
    // Ceny klientów wg marży [212] (Marża kategorii › Ceny klientów)
    $r->addRoute('POST', '/ajax/category-margin/client-prices', [
        'controller' => App\Controllers\Web\Finances\Product\CategoryMarginController::class,
        'method' => 'applyClientPrices'
    ]);
    $r->addRoute('PUT', '/ajax/category-margin/client-prices/params', [
        'controller' => App\Controllers\Web\Finances\Product\CategoryMarginController::class,
        'method' => 'saveClientPriceParams'
    ]);

    $r->addRoute('GET', '/finances/sales-analytics', [
        'controller' => App\Controllers\Web\Finances\Product\SalesAnalyticsController::class,
        'method' => 'overview'
    ]);

    $r->addRoute('GET', '/ajax/finances/sales-analytics/product-trend', [
        'controller' => App\Controllers\Web\Finances\Product\SalesAnalyticsController::class,
        'method' => 'ajaxProductTrend'
    ]);

    // Dawny « Dashboard finansowy działów » : udział sprzedaży i personelu działów, 12 mies. — w Rentowności
    // Sprzedawalność w Belgii : ceny w sklepach z API panelu (konto : administrator), powiązania, progi
    $c = App\Controllers\Web\Finances\StorePriceController::class;
    $r->addRoute('GET', '/settings/panel-api', ['controller' => $c, 'method' => 'settings']);
    $r->addRoute('PUT', '/ajax/settings/panel-api', ['controller' => $c, 'method' => 'saveAccount']);
    $r->addRoute('POST', '/ajax/settings/panel-api/test', ['controller' => $c, 'method' => 'testAccount']);
    $r->addRoute('POST', '/ajax/store-prices/sync', ['controller' => $c, 'method' => 'syncPrices']);
    $r->addRoute('GET', '/ajax/store-prices/products/{id:\d+}/candidates', ['controller' => $c, 'method' => 'candidates']);
    $r->addRoute('PUT', '/ajax/store-prices/products/{id:\d+}/link', ['controller' => $c, 'method' => 'link']);
    $r->addRoute('PUT', '/ajax/store-prices/params', ['controller' => $c, 'method' => 'params']);

    $r->addRoute('GET', '/finances/section-dashboard', ['redirect' => '/dashboard?period=12m']);
    // Dawna « Sprzedaż wg działów » : filtr i tabela działów w Analizie sprzedaży (te same parametry)
    $r->addRoute('GET', '/finances/sales-by-department', ['redirect' => '/finances/sales-analytics']);

};
