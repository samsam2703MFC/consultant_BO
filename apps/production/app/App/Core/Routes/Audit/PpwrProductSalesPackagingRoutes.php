<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r): void {
    $r->addRoute('GET', '/ppwr/sales-packaging', ['controller' => App\Controllers\Web\Audit\PpwrProductSalesPackagingController::class, 'method' => 'index']);
    $r->addRoute('GET', '/audits/ppwr-product-packaging', ['controller' => App\Controllers\Web\Audit\PpwrProductSalesPackagingController::class, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/audits/ppwr-product-packaging/products/{id:\d+}/candidates', ['controller' => App\Controllers\Web\Audit\PpwrProductSalesPackagingController::class, 'method' => 'ajaxCandidates']);
    $r->addRoute('PATCH', '/ajax/audits/ppwr-product-packaging/products/{id:\d+}', ['controller' => App\Controllers\Web\Audit\PpwrProductSalesPackagingController::class, 'method' => 'ajaxSave']);
};
