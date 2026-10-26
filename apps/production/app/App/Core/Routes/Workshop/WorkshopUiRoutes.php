<?php
use FastRoute\RouteCollector;

return function (RouteCollector $r) {
    $c = App\Controllers\Web\Workshop\WorkshopUiController::class;
    $r->addRoute('GET', '/workshop/products', ['controller' => $c, 'method' => 'products']);
    // « Plan tygodnia i surowce » usunięty : planuje się na tablicy « Tydzień produkcji ».
    $r->addRoute('GET', '/workshop/planning', ['redirect' => '/workshop/gantt']);
    $r->addRoute('GET', '/workshop/gantt', ['controller' => $c, 'method' => 'gantt']);
    $r->addRoute('GET', '/workshop/sheet', ['controller' => $c, 'method' => 'sheet']);
    $r->addRoute('GET', '/workshop/manager', ['controller' => $c, 'method' => 'manager']);
    $r->addRoute('GET', '/workshop/supply', ['controller' => $c, 'method' => 'supply']);
    $r->addRoute('GET', '/workshop/deliveries', ['controller' => $c, 'method' => 'deliveries']);
    $r->addRoute('GET', '/workshop/team-sheet', ['controller' => $c, 'method' => 'teamSheet']);
    $r->addRoute('GET', '/workshop/execution', ['controller' => $c, 'method' => 'execution']);
    $r->addRoute('GET', '/workshop/workstations', ['controller' => $c, 'method' => 'workstations']);
    $r->addRoute('GET', '/workshop/data-sheet', ['controller' => $c, 'method' => 'dataSheet']);
    $r->addRoute('GET', '/workshop/timesheet', ['controller' => $c, 'method' => 'timesheet']);
    $r->addRoute('GET', '/workshop/traceability', ['controller' => App\Controllers\Web\Workshop\TraceabilityController::class, 'method' => 'index']);
    $r->addRoute('GET', '/workshop/traceability/photo/{id:\d+}', ['controller' => App\Controllers\Web\Workshop\TraceabilityController::class, 'method' => 'photo']);
    $r->addRoute('GET', '/kiosk', ['controller' => $c, 'method' => 'kiosk']);
    $r->addRoute('GET', '/kiosk/scan', ['controller' => $c, 'method' => 'kioskScan']);
    $r->addRoute('GET', '/driver', ['controller' => $c, 'method' => 'driver']);
    $r->addRoute('POST', '/ajax/workshop/token', ['controller' => $c, 'method' => 'token']);
};
