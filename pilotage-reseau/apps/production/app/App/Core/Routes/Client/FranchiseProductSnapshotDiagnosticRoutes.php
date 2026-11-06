<?php

use FastRoute\RouteCollector;

return function(RouteCollector $r) {
    $r->addRoute('GET', '/clients/{id:\d+}/franchise-product-snapshot', [
        'controller' => \App\Controllers\Web\Client\PriceList\FranchiseProductSnapshotDiagnosticController::class,
        'method' => 'overview',
    ]);
};
