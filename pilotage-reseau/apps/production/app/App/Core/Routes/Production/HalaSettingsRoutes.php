<?php
use FastRoute\RouteCollector;

// [409] Produkcja › Tablet hali : kroki dnia grup i strefy sprzątania (Jakość, Kierownik produkcji — droit « hala »).
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Production\HalaSettingsController::class;
    $r->addRoute('GET', '/production/hala', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/production/hala', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/production/hala/group', ['controller' => $c, 'method' => 'group']);
    $r->addRoute('POST', '/ajax/production/hala/zone', ['controller' => $c, 'method' => 'zone']);
};
