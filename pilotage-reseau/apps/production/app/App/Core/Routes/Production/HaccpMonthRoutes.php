<?php
use FastRoute\RouteCollector;

// [380] Asystent « HACCP miesiąca » (Jakość, Kierownik produkcji, Brygadzista, Kierowca — co miesiąc) : przegląd → do wyjaśnienia → zatwierdź.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Production\HaccpMonthController::class;
    $r->addRoute('GET', '/production/haccp-month', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/production/haccp-month', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/production/haccp-month/decision', ['controller' => $c, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/production/haccp-month/finish', ['controller' => $c, 'method' => 'finish']);
};
