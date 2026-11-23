<?php
use FastRoute\RouteCollector;

// [379] Asystent « Reklamacje klientów » (Specjalista ds. jakości, co tydzień) : przegląd → do wyjaśnienia → zatwierdź.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Client\Complaint\ComplaintsReviewController::class;
    $r->addRoute('GET', '/franchise-complaints/review', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/franchise-complaints/review', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/franchise-complaints/review/decision', ['controller' => $c, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/franchise-complaints/review/finish', ['controller' => $c, 'method' => 'finish']);
};
