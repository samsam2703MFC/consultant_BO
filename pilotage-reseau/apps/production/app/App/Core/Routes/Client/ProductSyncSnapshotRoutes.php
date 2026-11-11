<?php

use FastRoute\RouteCollector;

return function(RouteCollector $r) {
    $r->addRoute('POST', '/ajax/clients/{id:\d+}/franchise-product-snapshot/refresh', [
        'controller' => \App\Controllers\Web\Client\PriceList\FranchiseProductSnapshotController::class,
        'method' => 'refresh',
    ]);

    $r->addRoute('GET', '/ajax/clients/{id:\d+}/franchise-product-snapshot/latest-successful', [
        'controller' => \App\Controllers\Web\Client\PriceList\FranchiseProductSnapshotController::class,
        'method' => 'latestSuccessful',
    ]);

    $r->addRoute('GET', '/ajax/clients/{id:\d+}/franchise-product-snapshot/latest-attempt', [
        'controller' => \App\Controllers\Web\Client\PriceList\FranchiseProductSnapshotController::class,
        'method' => 'latestAttempt',
    ]);
};
