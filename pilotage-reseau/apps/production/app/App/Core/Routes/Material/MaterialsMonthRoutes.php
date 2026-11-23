<?php
use FastRoute\RouteCollector;

// [401] Asystent « Kartoteka surowców » (Kierownik produkcji — co miesiąc) : przegląd → decyzja → zatwierdź.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Material\MaterialsMonthController::class;
    $r->addRoute('GET', '/materials/month', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/materials/month', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/materials/month/decision', ['controller' => $c, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/materials/month/finish', ['controller' => $c, 'method' => 'finish']);
};
