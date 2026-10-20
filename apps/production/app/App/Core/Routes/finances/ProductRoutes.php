<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/products/price-structure', ['redirect' => '/products/all']);

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

    $r->addRoute('GET', '/finances/sales-analytics', [
        'controller' => App\Controllers\Web\Finances\Product\SalesAnalyticsController::class,
        'method' => 'overview'
    ]);

    $r->addRoute('GET', '/ajax/finances/sales-analytics/product-trend', [
        'controller' => App\Controllers\Web\Finances\Product\SalesAnalyticsController::class,
        'method' => 'ajaxProductTrend'
    ]);

    // Dawny « Dashboard finansowy działów » : udział sprzedaży i personelu działów, 12 mies. — w Rentowności
    $r->addRoute('GET', '/finances/section-dashboard', ['redirect' => '/dashboard?period=12m']);
    // Dawna « Sprzedaż wg działów » : filtr i tabela działów w Analizie sprzedaży (te same parametry)
    $r->addRoute('GET', '/finances/sales-by-department', ['redirect' => '/finances/sales-analytics']);

};
