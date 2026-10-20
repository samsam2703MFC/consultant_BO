<?php
use FastRoute\RouteCollector;

return function (RouteCollector $r) {
    $c = App\Controllers\Web\Workshop\WorkshopUiController::class;
    $r->addRoute('GET', '/workshop/products', ['controller' => $c, 'method' => 'products']);
    $r->addRoute('GET', '/workshop/planning', ['controller' => $c, 'method' => 'planning']);
    $r->addRoute('GET', '/workshop/gantt', ['controller' => $c, 'method' => 'gantt']);
    $r->addRoute('GET', '/workshop/execution', ['controller' => $c, 'method' => 'execution']);
    $r->addRoute('GET', '/workshop/timesheet', ['controller' => $c, 'method' => 'timesheet']);
    $r->addRoute('GET', '/workshop/traceability', ['controller' => App\Controllers\Web\Workshop\TraceabilityController::class, 'method' => 'index']);
    $r->addRoute('GET', '/workshop/traceability/photo/{id:\d+}', ['controller' => App\Controllers\Web\Workshop\TraceabilityController::class, 'method' => 'photo']);
    $r->addRoute('GET', '/kiosk', ['controller' => $c, 'method' => 'kiosk']);
    $r->addRoute('GET', '/kiosk/scan', ['controller' => $c, 'method' => 'kioskScan']);
    $r->addRoute('POST', '/ajax/workshop/token', ['controller' => $c, 'method' => 'token']);
};
