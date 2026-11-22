<?php
use FastRoute\RouteCollector;

// [385] Asystent « Dane klientów » (Administracja — co miesiąc) : przegląd → do wyjaśnienia → zatwierdź.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Client\CustomersDataCheckController::class;
    $r->addRoute('GET', '/clients/data-check', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/clients/data-check', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/clients/data-check/decision', ['controller' => $c, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/clients/data-check/finish', ['controller' => $c, 'method' => 'finish']);
};
