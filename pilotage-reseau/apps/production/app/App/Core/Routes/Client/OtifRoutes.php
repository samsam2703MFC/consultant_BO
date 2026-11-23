<?php
use FastRoute\RouteCollector;

// [375] Asystent « Terminowość tygodnia (OTIF) » (Kierownik operacyjny, co tydzień) : wynik tygodnia → przyczyny → podsumowanie.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Client\Order\OtifWeekController::class;
    $r->addRoute('GET', '/client-orders/otif', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/client-orders/otif', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/client-orders/otif/action', ['controller' => $c, 'method' => 'action']);
    $r->addRoute('POST', '/ajax/client-orders/otif/finish', ['controller' => $c, 'method' => 'finish']);
};
