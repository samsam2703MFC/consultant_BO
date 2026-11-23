<?php
use FastRoute\RouteCollector;

// [382] Asystent « Powiadomienia o dostawie » (Logistyka — codziennie) : przegląd → do wyjaśnienia → zatwierdź.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Logistics\DeliveryNoticesCheckController::class;
    $r->addRoute('GET', '/logistics/notices-check', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/logistics/notices-check', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/logistics/notices-check/decision', ['controller' => $c, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/logistics/notices-check/finish', ['controller' => $c, 'method' => 'finish']);
};
