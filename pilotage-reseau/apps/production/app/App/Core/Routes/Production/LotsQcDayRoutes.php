<?php
use FastRoute\RouteCollector;

// [377] Asystent « Partie i kontrola dnia » (Specjalista ds. jakości, codziennie) : przegląd → do wyjaśnienia → zatwierdź.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Production\LotsQcDayController::class;
    $r->addRoute('GET', '/workshop/traceability/day-check', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/workshop/traceability/day-check', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/workshop/traceability/day-check/result', ['controller' => $c, 'method' => 'result']);
    $r->addRoute('POST', '/ajax/workshop/traceability/day-check/finish', ['controller' => $c, 'method' => 'finish']);
};
