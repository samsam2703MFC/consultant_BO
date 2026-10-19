<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {
    
    // Category Profitability List
    $r->addRoute('GET', '/categories/profitability', [
        'controller' => App\Controllers\Web\Product\CategoryProfitabilityController::class,
        'method'     => 'list'
    ]);

    // Category Profitability Details
    $r->addRoute('GET', '/categories/{id:\d+}/profitability-details', [
        'controller' => App\Controllers\Web\Product\CategoryProfitabilityController::class,
        'method'     => 'details'
    ]);

    // AJAX: Calculate "What-if" scenarios for category
    $r->addRoute('POST', '/ajax/categories/{id:\d+}/profitability/calculate', [
        'controller' => App\Controllers\Web\Product\CategoryProfitabilityController::class,
        'method'     => 'ajaxCalculate'
    ]);
};
