<?php
use FastRoute\RouteCollector;

// [406] Asystent « Teczka do audytu i kontroli » (Kierownik produkcji — co kwartał) : przegląd → decyzja → zatwierdź ; lista dokumentów, teczka PDF.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Audit\AuditFolderController::class;
    $r->addRoute('GET', '/audit-folder', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/audit-folder', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('GET', '/ajax/audit-folder/pdf', ['controller' => $c, 'method' => 'pdf']);
    $r->addRoute('POST', '/ajax/audit-folder/decision', ['controller' => $c, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/audit-folder/item', ['controller' => $c, 'method' => 'item']);
    $r->addRoute('POST', '/ajax/audit-folder/item/remove', ['controller' => $c, 'method' => 'removeItem']);
    $r->addRoute('POST', '/ajax/audit-folder/finish', ['controller' => $c, 'method' => 'finish']);
};
