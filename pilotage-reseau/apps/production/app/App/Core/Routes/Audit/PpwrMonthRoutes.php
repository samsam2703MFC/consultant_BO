<?php
use FastRoute\RouteCollector;

// [397] Asystent « PPWR i opakowania » (Kierownik produkcji — co miesiąc) : przegląd → decyzja → zatwierdź.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Audit\PpwrMonthController::class;
    $r->addRoute('GET', '/ppwr/month', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/ppwr/month', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/ppwr/month/decision', ['controller' => $c, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/ppwr/month/finish', ['controller' => $c, 'method' => 'finish']);
};
