<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/products/price-structure', [
        'controller' => App\Controllers\Web\Finances\Product\PriceStructureController::class,
        'method' => 'overview'
    ]);

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

    $r->addRoute('GET', '/finances/section-dashboard', [
        'controller' => App\Controllers\Web\Finances\Product\SectionFinanceDashboardController::class,
        'method' => 'overview'
    ]);

    $r->addRoute('GET', '/finances/sales-by-department', [
        'controller' => App\Controllers\Web\Finances\Product\SalesByDepartmentController::class,
        'method' => 'overview'
    ]);

};
