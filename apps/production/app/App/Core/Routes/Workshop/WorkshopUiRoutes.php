<?php
use FastRoute\RouteCollector;

return function (RouteCollector $r) {
    $c = App\Controllers\Web\Workshop\WorkshopUiController::class;
    $r->addRoute('GET', '/workshop/products', ['controller' => $c, 'method' => 'products']);
    $r->addRoute('GET', '/workshop/planning', ['controller' => $c, 'method' => 'planning']);
    $r->addRoute('GET', '/workshop/gantt', ['controller' => $c, 'method' => 'gantt']);
    $r->addRoute('GET', '/workshop/optimizer', ['controller' => $c, 'method' => 'optimizer']);
    $r->addRoute('GET', '/workshop/execution', ['controller' => $c, 'method' => 'execution']);
    $r->addRoute('GET', '/workshop/timesheet', ['controller' => $c, 'method' => 'timesheet']);
    $r->addRoute('GET', '/kiosk', ['controller' => $c, 'method' => 'kiosk']);
    $r->addRoute('POST', '/ajax/workshop/token', ['controller' => $c, 'method' => 'token']);
};
