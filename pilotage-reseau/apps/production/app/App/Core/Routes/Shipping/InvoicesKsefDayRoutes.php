<?php
use FastRoute\RouteCollector;

// [378] Asystent « Faktury i KSeF dnia » (Administracja i fakturowanie, codziennie) : przegląd → do wyjaśnienia → zatwierdź.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Shipping\InvoicesKsefDayController::class;
    $r->addRoute('GET', '/shipments/ksef-day', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/shipments/ksef-day', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/shipments/ksef-day/result', ['controller' => $c, 'method' => 'result']);
    $r->addRoute('POST', '/ajax/shipments/ksef-day/finish', ['controller' => $c, 'method' => 'finish']);
};
