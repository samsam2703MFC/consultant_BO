<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {
    $c = App\Controllers\Web\Client\ClientSpaceController::class;

    $r->addRoute('GET', '/clients/{id:\d+}/space', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/clients/{id:\d+}/space/documents.pdf', ['controller' => $c, 'method' => 'documentsPdf']);
    $r->addRoute('GET', '/ajax/clients/{id:\d+}/space/goal.pdf', ['controller' => $c, 'method' => 'goalPdf']);
    $r->addRoute('PUT', '/ajax/clients/space/category-coefficients', ['controller' => $c, 'method' => 'saveCategoryCoefficients']);
};
