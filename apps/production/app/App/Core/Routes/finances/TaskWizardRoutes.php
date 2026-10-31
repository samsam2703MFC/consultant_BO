<?php
use FastRoute\RouteCollector;

// Lot 4 [230] : kreatory zadań — płatności dnia (Administracja) i ocena dostawców co kwartał (Kierownik produkcji).
return function (RouteCollector $r) {
    $p = App\Controllers\Web\Finances\PaymentsDayController::class;
    $r->addRoute('GET', '/finances/payments-day', ['controller' => $p, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/finances/payments-day', ['controller' => $p, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/finances/payments-day/paid', ['controller' => $p, 'method' => 'paid']);
    $r->addRoute('POST', '/ajax/finances/payments-day/finish', ['controller' => $p, 'method' => 'finish']);

    $s = App\Controllers\Web\Purchasing\SupplierReviewController::class;
    $r->addRoute('GET', '/purchasing/supplier-review', ['controller' => $s, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/purchasing/supplier-review', ['controller' => $s, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/purchasing/supplier-review/grades', ['controller' => $s, 'method' => 'grades']);
    $r->addRoute('POST', '/ajax/purchasing/supplier-review/tender', ['controller' => $s, 'method' => 'tender']);
    $r->addRoute('POST', '/ajax/purchasing/supplier-review/finish', ['controller' => $s, 'method' => 'finish']);
};
