<?php
use FastRoute\RouteCollector;

// [275] Wejście do ERP przez QR (czas pracy) : ekran QR, stan dnia, kod, oczekiwanie na odbicie ; ustawienie stanowisk.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Organization\ErpEntryController::class;

    $r->addRoute('GET', '/erp-entry', ['controller' => $c, 'method' => 'page']);
    $r->addRoute('GET', '/ajax/erp-entry/state', ['controller' => $c, 'method' => 'state']);
    $r->addRoute('GET', '/ajax/erp-entry/qr', ['controller' => $c, 'method' => 'qr']);
    $r->addRoute('GET', '/ajax/erp-entry/status', ['controller' => $c, 'method' => 'status']);
    $r->addRoute('PUT', '/ajax/organization/erp-qr/{id:\d+}', ['controller' => $c, 'method' => 'savePosition']);
};
