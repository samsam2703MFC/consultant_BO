<?php
use FastRoute\RouteCollector;

// [404] Asystent « Badania i szkolenia » (Kierownik produkcji — co miesiąc) : przegląd → decyzja (umówione, aktualne, nie dotyczy) → zatwierdź ; rejestr szkoleń.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Hr\StaffDeadlinesController::class;
    $r->addRoute('GET', '/hr/deadlines', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/hr/deadlines', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/hr/deadlines/decision', ['controller' => $c, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/hr/deadlines/training', ['controller' => $c, 'method' => 'training']);
    $r->addRoute('POST', '/ajax/hr/deadlines/training/remove', ['controller' => $c, 'method' => 'removeTraining']);
    $r->addRoute('POST', '/ajax/hr/deadlines/finish', ['controller' => $c, 'method' => 'finish']);
};
