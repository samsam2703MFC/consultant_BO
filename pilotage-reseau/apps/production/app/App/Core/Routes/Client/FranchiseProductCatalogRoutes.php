<?php

use FastRoute\RouteCollector;

return function(RouteCollector $r) {
    $r->addRoute('GET', '/franchise-products', [
        'controller' => \App\Controllers\Web\Client\PriceList\FranchiseProductCatalogController::class,
        'method' => 'overview',
    ]);
};
