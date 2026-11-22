<?php
use FastRoute\RouteCollector;

// [381] Asystent « Palety zwrotne » (Logistyka, Kierowca-dostawca — co tydzień) : przegląd → do wyjaśnienia → zatwierdź.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Logistics\PalletsReturnsController::class;
    $r->addRoute('GET', '/logistics/pallets-returns', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/logistics/pallets-returns', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/logistics/pallets-returns/decision', ['controller' => $c, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/logistics/pallets-returns/finish', ['controller' => $c, 'method' => 'finish']);
};
