<?php
use FastRoute\RouteCollector;

// [402] Asystent « Dokumenty wysyłek miesiąca » (Kierownik produkcji — co miesiąc) : przegląd → decyzja → zatwierdź.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Shipping\ShippingDocsMonthController::class;
    $r->addRoute('GET', '/shipments/documents-month', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/shipments/documents-month', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/shipments/documents-month/decision', ['controller' => $c, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/shipments/documents-month/finish', ['controller' => $c, 'method' => 'finish']);
};
