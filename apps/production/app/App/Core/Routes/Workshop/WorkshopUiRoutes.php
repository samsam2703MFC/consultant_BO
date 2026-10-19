<?php
use FastRoute\RouteCollector;

return function (RouteCollector $r) {
    $c = App\Controllers\Web\Workshop\WorkshopUiController::class;
    $r->addRoute('GET', '/workshop/products', ['controller' => $c, 'method' => 'products']);
    $r->addRoute('GET', '/workshop/planning', ['controller' => $c, 'method' => 'planning']);
    $r->addRoute('GET', '/workshop/gantt', ['controller' => $c, 'method' => 'gantt']);
    $r->addRoute('GET', '/workshop/optimizer', ['controller' => $c, 'method' => 'optimizer']);
    $r->addRoute('POST', '/ajax/workshop/token', ['controller' => $c, 'method' => 'token']);
};
