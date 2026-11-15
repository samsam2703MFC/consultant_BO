<?php
use FastRoute\RouteCollector;

// [285] Raport stanowisk (makieta 39) : zadania z minutami, wypełnienie dnia / tygodnia / miesiąca, zrobione — administrator.
return function (RouteCollector $r) {
    $r->addRoute('GET', '/organization/position-report', ['controller' => App\Controllers\Web\Organization\PositionReportController::class, 'method' => 'index']);
};
