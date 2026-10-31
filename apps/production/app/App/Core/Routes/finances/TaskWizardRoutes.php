<?php
use FastRoute\RouteCollector;

// Lot 4 [230] : kreatory zadań — płatności dnia (Administracja) i ocena dostawców co kwartał (Kierownik produkcji).
return function (RouteCollector $r) {
    $p = App\Controllers\Web\Finances\PaymentsDayController::class;
    $r->addRoute('GET', '/finances/payments-day', ['controller' => $p, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/finances/payments-day', ['controller' => $p, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/finances/payments-day/paid', ['controller' => $p, 'method' => 'paid']);
    $r->addRoute('POST', '/ajax/finances/payments-day/finish', ['controller' => $p, 'method' => 'finish']);

    // Wpłata z e-maila [233] (makieta 9) : potwierdzenia przelewów klientów z Poczty.
    $pp = App\Controllers\Web\Finances\PaymentProofController::class;
    $r->addRoute('GET', '/ajax/finances/payment-proofs', ['controller' => $pp, 'method' => 'candidates']);
    $r->addRoute('GET', '/ajax/finances/payment-proofs/mail/{id:\d+}', ['controller' => $pp, 'method' => 'mail']);
    $r->addRoute('POST', '/ajax/finances/payment-proofs/mail/{id:\d+}/reject', ['controller' => $pp, 'method' => 'rejectMail']);
    $r->addRoute('POST', '/ajax/finances/payment-proofs/read', ['controller' => $pp, 'method' => 'read']);
    $r->addRoute('GET', '/ajax/finances/payment-proofs/{id:\d+}', ['controller' => $pp, 'method' => 'show']);
    $r->addRoute('POST', '/ajax/finances/payment-proofs/{id:\d+}/confirm', ['controller' => $pp, 'method' => 'confirm']);
    $r->addRoute('POST', '/ajax/finances/payment-proofs/{id:\d+}/reject', ['controller' => $pp, 'method' => 'reject']);
    $r->addRoute('GET', '/finances/payment-proofs/{id:\d+}/file', ['controller' => $pp, 'method' => 'file']);

    $s = App\Controllers\Web\Purchasing\SupplierReviewController::class;
    $r->addRoute('GET', '/purchasing/supplier-review', ['controller' => $s, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/purchasing/supplier-review', ['controller' => $s, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/purchasing/supplier-review/grades', ['controller' => $s, 'method' => 'grades']);
    $r->addRoute('POST', '/ajax/purchasing/supplier-review/tender', ['controller' => $s, 'method' => 'tender']);
    $r->addRoute('POST', '/ajax/purchasing/supplier-review/finish', ['controller' => $s, 'method' => 'finish']);
};
