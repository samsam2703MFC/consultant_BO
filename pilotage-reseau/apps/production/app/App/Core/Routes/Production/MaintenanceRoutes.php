<?php
use FastRoute\RouteCollector;

// [405] Asystent « Serwisy i przeglądy maszyn » (Kierownik produkcji — co miesiąc, w każdej chwili) : przegląd → decyzja → zatwierdź ; rejestry urządzeń, przeglądów i zgłoszeń.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Production\MaintenanceController::class;
    $r->addRoute('GET', '/maintenance', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/maintenance', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/maintenance/decision', ['controller' => $c, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/maintenance/equipment', ['controller' => $c, 'method' => 'equipment']);
    $r->addRoute('POST', '/ajax/maintenance/check', ['controller' => $c, 'method' => 'check']);
    $r->addRoute('POST', '/ajax/maintenance/check/done', ['controller' => $c, 'method' => 'checkDone']);
    $r->addRoute('POST', '/ajax/maintenance/ticket', ['controller' => $c, 'method' => 'ticket']);
    $r->addRoute('POST', '/ajax/maintenance/ticket/status', ['controller' => $c, 'method' => 'ticketStatus']);
    $r->addRoute('POST', '/ajax/maintenance/finish', ['controller' => $c, 'method' => 'finish']);
};
