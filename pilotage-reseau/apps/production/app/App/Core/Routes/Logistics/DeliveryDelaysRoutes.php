<?php
use FastRoute\RouteCollector;

// [374] Asystent « Opóźnienia dostaw » (Kierownik operacyjny, codziennie) : przegląd → do wyjaśnienia → zatwierdź.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Logistics\DeliveryDelaysController::class;
    $r->addRoute('GET', '/logistics/delays', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/logistics/delays', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/logistics/delays/decision', ['controller' => $c, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/logistics/delays/finish', ['controller' => $c, 'method' => 'finish']);
};
