<?php
use FastRoute\RouteCollector;

// [398] Asystent « Intrastat miesiąca » (Kierownik produkcji — co miesiąc) : przegląd → decyzja (kod CN, kurs) → zatwierdź ; CSV do PUESC.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Shipping\IntrastatMonthController::class;
    $r->addRoute('GET', '/intrastat/month', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/intrastat/month', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('GET', '/ajax/intrastat/month/csv', ['controller' => $c, 'method' => 'csv']);
    $r->addRoute('POST', '/ajax/intrastat/month/decision', ['controller' => $c, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/intrastat/month/code', ['controller' => $c, 'method' => 'code']);
    $r->addRoute('POST', '/ajax/intrastat/month/rate', ['controller' => $c, 'method' => 'rate']);
    $r->addRoute('POST', '/ajax/intrastat/month/finish', ['controller' => $c, 'method' => 'finish']);
};
