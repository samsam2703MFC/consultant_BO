<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {
    
    // Product Profitability List (MVP v1)
    $r->addRoute('GET', '/products/profitability', [
        'controller' => App\Controllers\Web\Product\ProfitabilityController::class,
        'method'     => 'list'
    ]);

    // Product Profitability Details (MVP v1)
    $r->addRoute('GET', '/products/{id:\d+}/profitability-details', [
        'controller' => App\Controllers\Web\Product\ProfitabilityController::class,
        'method'     => 'details'
    ]);

    // AJAX: Calculate "What-if" scenarios
    $r->addRoute('POST', '/ajax/products/{id:\d+}/profitability/calculate', [
        'controller' => App\Controllers\Web\Product\ProfitabilityController::class,
        'method'     => 'ajaxCalculate'
    ]);
};
