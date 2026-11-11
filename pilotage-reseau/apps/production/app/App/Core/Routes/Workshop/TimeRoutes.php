<?php
use FastRoute\RouteCollector;

// [262] Czas pracy : strony (zakładki Dzień, Miesiąc, Urlopy i dni wolne, Nadgodziny), karta A4 (PDF), zapisy (ajax, prawo « time »).
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Workshop\TimeController::class;

    $r->addRoute('GET', '/time', ['controller' => $c, 'method' => 'day']);
    $r->addRoute('GET', '/time/month', ['controller' => $c, 'method' => 'month']);
    $r->addRoute('GET', '/time/leave', ['controller' => $c, 'method' => 'leave']);
    $r->addRoute('GET', '/time/overtime', ['controller' => $c, 'method' => 'overtime']);
    $r->addRoute('GET', '/time/sheet.pdf', ['controller' => $c, 'method' => 'sheetPdf']);

    $r->addRoute('GET', '/ajax/time/day', ['controller' => $c, 'method' => 'dayData']);
    $r->addRoute('GET', '/ajax/time/month', ['controller' => $c, 'method' => 'monthData']);
    $r->addRoute('GET', '/ajax/time/leave', ['controller' => $c, 'method' => 'leaveData']);
    $r->addRoute('GET', '/ajax/time/overtime', ['controller' => $c, 'method' => 'overtimeData']);
    $r->addRoute('PUT', '/ajax/time/reasons', ['controller' => $c, 'method' => 'saveReason']);
    $r->addRoute('POST', '/ajax/time/days/{day:\d{4}-\d{2}-\d{2}}/validate', ['controller' => $c, 'method' => 'validateDay']);
    $r->addRoute('DELETE', '/ajax/time/days/{day:\d{4}-\d{2}-\d{2}}/validate', ['controller' => $c, 'method' => 'reopenDay']);
    $r->addRoute('PATCH', '/ajax/time/clocks/{id:\d+}', ['controller' => $c, 'method' => 'correctClock']);
    $r->addRoute('POST', '/ajax/time/clocks', ['controller' => $c, 'method' => 'addClock']);
    $r->addRoute('POST', '/ajax/time/months/{month:\d{4}-\d{2}}/close', ['controller' => $c, 'method' => 'closeMonth']);
    $r->addRoute('DELETE', '/ajax/time/months/{month:\d{4}-\d{2}}/close', ['controller' => $c, 'method' => 'reopenMonth']);
    // [266] Adresy e-mail pracowników (zaszyfrowane) i karta miesiąca e-mailem.
    $r->addRoute('GET', '/ajax/time/contacts', ['controller' => $c, 'method' => 'contacts']);
    $r->addRoute('PUT', '/ajax/time/contacts', ['controller' => $c, 'method' => 'saveContacts']);
    $r->addRoute('POST', '/ajax/time/months/{month:\d{4}-\d{2}}/mail', ['controller' => $c, 'method' => 'mailSheets']);
    $r->addRoute('POST', '/ajax/time/absences', ['controller' => $c, 'method' => 'addAbsence']);
    $r->addRoute('DELETE', '/ajax/time/absences/{id:\d+}', ['controller' => $c, 'method' => 'deleteAbsence']);
    $r->addRoute('PUT', '/ajax/time/leave/{id:\d+}', ['controller' => $c, 'method' => 'saveLeaveRight']);
    $r->addRoute('POST', '/ajax/time/comps', ['controller' => $c, 'method' => 'proposeComp']);
    $r->addRoute('POST', '/ajax/time/comps/{id:\d+}/sign', ['controller' => $c, 'method' => 'signComp']);
    $r->addRoute('DELETE', '/ajax/time/comps/{id:\d+}', ['controller' => $c, 'method' => 'cancelComp']);
};
