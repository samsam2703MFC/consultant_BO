<?php
use FastRoute\RouteCollector;

// [384] Asystent « Nowe produkty » (Kierownik produkcji — co miesiąc) : przegląd → do wyjaśnienia → zatwierdź.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Product\LaunchesReviewController::class;
    $r->addRoute('GET', '/products/launches-review', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/products/launches-review', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/products/launches-review/decision', ['controller' => $c, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/products/launches-review/finish', ['controller' => $c, 'method' => 'finish']);
};
