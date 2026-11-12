<?php
use FastRoute\RouteCollector;

// [281] Kontrola (profil CEO) : wszyscy dziś, tydzień stanowisk, decyzje (odblokuj, przypomnij — istniejące wywołania).
return function (RouteCollector $r) {
    $r->addRoute('GET', '/control', ['controller' => App\Controllers\Web\Organization\ControlController::class, 'method' => 'index']);
};
