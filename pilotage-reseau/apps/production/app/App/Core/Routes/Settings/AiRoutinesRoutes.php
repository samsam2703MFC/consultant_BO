<?php
use FastRoute\RouteCollector;

// [387] Rutyny poranne AI (pilot) : ustawienia (administrator) i decyzja o propozycji w kroku 2 asystenta.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Settings\AiRoutinesController::class;
    $r->addRoute('GET', '/ajax/settings/ai/routines', ['controller' => $c, 'method' => 'state']);
    $r->addRoute('PUT', '/ajax/settings/ai/routines', ['controller' => $c, 'method' => 'save']);
    $r->addRoute('POST', '/ajax/settings/ai/routines/{code:[a-z_]+}/run', ['controller' => $c, 'method' => 'runNow']);
    $r->addRoute('GET', '/ajax/settings/ai/routines/{code:[a-z_]+}/preview', ['controller' => $c, 'method' => 'preview']);
    $r->addRoute('POST', '/ajax/ai/proposals/{id:\d+}', ['controller' => $c, 'method' => 'mark']);
};
