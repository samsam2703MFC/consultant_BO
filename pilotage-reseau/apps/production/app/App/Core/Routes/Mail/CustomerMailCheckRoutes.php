<?php
use FastRoute\RouteCollector;

// [383] Asystent « Poczta od klientów » (Administracja — codziennie) : przegląd → do wyjaśnienia → zatwierdź.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Mail\CustomerMailCheckController::class;
    $r->addRoute('GET', '/mail/customer-check', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/mail/customer-check', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/mail/customer-check/decision', ['controller' => $c, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/mail/customer-check/finish', ['controller' => $c, 'method' => 'finish']);
};
