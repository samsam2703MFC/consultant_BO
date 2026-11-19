<?php
use FastRoute\RouteCollector;

// [311] Mój podpis : podpis użytkownika ERP do dokumentów — pole 22 CMR (nadawca) wysyłek, które wystawia.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Organization\UserSignatureController::class;
    $r->addRoute('GET', '/my-signature', ['controller' => $c, 'method' => 'page']);
    $r->addRoute('GET', '/ajax/my-signature/image', ['controller' => $c, 'method' => 'image']);
    $r->addRoute('POST', '/ajax/my-signature', ['controller' => $c, 'method' => 'save']);
    $r->addRoute('DELETE', '/ajax/my-signature', ['controller' => $c, 'method' => 'delete']);
};
