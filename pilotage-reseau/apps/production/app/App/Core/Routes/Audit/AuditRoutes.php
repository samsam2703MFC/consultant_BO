<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {
    $r->addRoute('GET', '/audits/product-weights', [
        'controller' => App\Controllers\Web\Audit\ProductWeightAuditController::class,
        'method' => 'index',
    ]);
    $r->addRoute('GET', '/ajax/audits/product-weights', [
        'controller' => App\Controllers\Web\Audit\ProductWeightAuditController::class,
        'method' => 'ajaxList',
    ]);
    $r->addRoute('PATCH', '/ajax/audits/product-weights/{id:\d+}', [
        'controller' => App\Controllers\Web\Audit\ProductWeightAuditController::class,
        'method' => 'ajaxUpdateWeight',
    ]);
};
