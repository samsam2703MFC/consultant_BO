<?php
use FastRoute\RouteCollector;

// [403] Asystent « Stany magazynowe na koniec miesiąca » (Kierownik produkcji — co miesiąc) : zestawienie → decyzja i
// przekazanie → zatwierdź ; PDF i CSV zapisanego zestawienia.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Stock\StockMonthController::class;
    $r->addRoute('GET', '/stock/month-end', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/stock/month-end', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/stock/month-end/snapshot', ['controller' => $c, 'method' => 'snapshot']);
    $r->addRoute('POST', '/ajax/stock/month-end/decision', ['controller' => $c, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/stock/month-end/finish', ['controller' => $c, 'method' => 'finish']);
    $r->addRoute('GET', '/ajax/stock/month-end/pdf', ['controller' => $c, 'method' => 'pdf']);
    $r->addRoute('GET', '/ajax/stock/month-end/csv', ['controller' => $c, 'method' => 'csv']);
};
