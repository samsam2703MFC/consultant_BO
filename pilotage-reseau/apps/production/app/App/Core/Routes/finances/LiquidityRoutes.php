<?php
use FastRoute\RouteCollector;

// [373] Asystent « Płynność i gotówka » (Kierownik operacyjny, co tydzień) : sytuacja → plan → podsumowanie.
return function (RouteCollector $r) {
    $l = App\Controllers\Web\Finances\LiquidityCheckController::class;
    $r->addRoute('GET', '/finances/liquidity', ['controller' => $l, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/finances/liquidity', ['controller' => $l, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/finances/liquidity/balance', ['controller' => $l, 'method' => 'balance']);
    $r->addRoute('POST', '/ajax/finances/liquidity/decision', ['controller' => $l, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/finances/liquidity/finish', ['controller' => $l, 'method' => 'finish']);
};
