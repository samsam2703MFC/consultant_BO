<?php
use FastRoute\RouteCollector;

// [372] SMS de commande aux clients (assistants « Zamówienia dnia » et « Klienci bez zamówienia ») : brouillon et envoi.
return function (RouteCollector $r) {
    $c = \App\Controllers\Web\Client\Order\OrderSmsController::class;
    $r->addRoute('GET', '/ajax/client-orders/sms/draft', ['controller' => $c, 'method' => 'draft']);
    $r->addRoute('POST', '/ajax/client-orders/sms/send', ['controller' => $c, 'method' => 'send']);
};
