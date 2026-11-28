<?php
use FastRoute\RouteCollector;

// [440] Różnice kursowe (Finanse) : wpłaty klientów w walucie, kursy NBP, wyciągi ING, zestawienie miesiąca (PDF / CSV).
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Finances\FxDifferenceController::class;
    $r->addRoute('GET', '/finances/fx-differences', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/finances/fx-differences', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('GET', '/ajax/finances/fx-differences/pdf', ['controller' => $c, 'method' => 'pdf']);
    $r->addRoute('GET', '/ajax/finances/fx-differences/csv', ['controller' => $c, 'method' => 'csv']);
    $r->addRoute('POST', '/ajax/finances/fx-differences/statements', ['controller' => $c, 'method' => 'upload']);
    $r->addRoute('GET', '/ajax/finances/fx-differences/statements/{id:\d+}/file', ['controller' => $c, 'method' => 'statementFile']);
    $r->addRoute('POST', '/ajax/finances/fx-differences/statements/{id:\d+}/delete', ['controller' => $c, 'method' => 'deleteStatement']);
    $r->addRoute('POST', '/ajax/finances/fx-differences/lines', ['controller' => $c, 'method' => 'saveLine']);
    $r->addRoute('GET', '/ajax/finances/fx-differences/lines/{id:\d+}', ['controller' => $c, 'method' => 'line']);
    $r->addRoute('POST', '/ajax/finances/fx-differences/lines/{id:\d+}', ['controller' => $c, 'method' => 'saveLine']);
    $r->addRoute('POST', '/ajax/finances/fx-differences/lines/{id:\d+}/allocate', ['controller' => $c, 'method' => 'allocate']);
    $r->addRoute('POST', '/ajax/finances/fx-differences/lines/{id:\d+}/ignore', ['controller' => $c, 'method' => 'ignore']);
    $r->addRoute('POST', '/ajax/finances/fx-differences/lines/{id:\d+}/delete', ['controller' => $c, 'method' => 'deleteLine']);
};
