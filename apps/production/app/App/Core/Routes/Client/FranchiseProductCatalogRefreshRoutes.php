<?php

use FastRoute\RouteCollector;

return function(RouteCollector $r) {
    $r->addRoute('POST', '/ajax/franchise-products/refresh-all', [
        'controller' => \App\Controllers\Web\Client\PriceList\FranchiseProductCatalogRefreshController::class,
        'method' => 'refreshAll',
    ]);
};
