<?php
use FastRoute\RouteCollector;

// [271] Rail « HR » : pracownicy, karta pracownika (dane, dokumenty, notatki), dokumenty, notatki miesiąca, ustawienia.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Hr\HrController::class;

    $r->addRoute('GET', '/hr', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/hr/documents', ['controller' => $c, 'method' => 'documentsPage']);
    $r->addRoute('GET', '/hr/notes', ['controller' => $c, 'method' => 'notesPage']);
    $r->addRoute('GET', '/hr/templates', ['controller' => $c, 'method' => 'templatesPage']);
    $r->addRoute('GET', '/hr/templates/{code:[a-z_]+}/docx', ['controller' => $c, 'method' => 'templateDocx']);
    $r->addRoute('GET', '/hr/succession', ['controller' => $c, 'method' => 'successionPage']);
    $r->addRoute('GET', '/hr/settings', ['controller' => $c, 'method' => 'settingsPage']);
    $r->addRoute('GET', '/hr/document/{id:\d+}', ['controller' => $c, 'method' => 'download']);

    $r->addRoute('GET', '/ajax/hr/people', ['controller' => $c, 'method' => 'people']);
    $r->addRoute('GET', '/ajax/hr/people/{id:\d+}', ['controller' => $c, 'method' => 'card']);
    $r->addRoute('PUT', '/ajax/hr/people/{id:\d+}', ['controller' => $c, 'method' => 'save']);
    $r->addRoute('POST', '/ajax/hr/people/{id:\d+}/documents', ['controller' => $c, 'method' => 'upload']);
    $r->addRoute('POST', '/ajax/hr/people/{id:\d+}/notes', ['controller' => $c, 'method' => 'addNote']);
    $r->addRoute('DELETE', '/ajax/hr/documents/{id:\d+}', ['controller' => $c, 'method' => 'deleteDocument']);
    $r->addRoute('DELETE', '/ajax/hr/notes/{id:\d+}', ['controller' => $c, 'method' => 'deleteNote']);
    $r->addRoute('GET', '/ajax/hr/documents', ['controller' => $c, 'method' => 'documents']);
    $r->addRoute('GET', '/ajax/hr/notes', ['controller' => $c, 'method' => 'monthNotes']);
    $r->addRoute('POST', '/ajax/hr/notes/summary', ['controller' => $c, 'method' => 'sendSummary']);
    $r->addRoute('GET', '/ajax/hr/rules', ['controller' => $c, 'method' => 'rules']);
    $r->addRoute('PUT', '/ajax/hr/rules', ['controller' => $c, 'method' => 'saveRules']);
    // [279] Szybka notatka i follow-upy
    $r->addRoute('GET', '/ajax/hr/quick-note', ['controller' => $c, 'method' => 'quickOptions']);
    $r->addRoute('POST', '/ajax/hr/quick-note', ['controller' => $c, 'method' => 'quickAdd']);
    $r->addRoute('GET', '/ajax/hr/followups', ['controller' => $c, 'method' => 'followups']);
    $r->addRoute('POST', '/ajax/hr/followups/{id:\d+}/done', ['controller' => $c, 'method' => 'followupDone']);
    // [278] Sukcesja
    $r->addRoute('GET', '/ajax/hr/succession', ['controller' => $c, 'method' => 'succession']);
    $r->addRoute('POST', '/ajax/hr/succession', ['controller' => $c, 'method' => 'successionOpen']);
    $r->addRoute('PUT', '/ajax/hr/succession/{id:\d+}', ['controller' => $c, 'method' => 'successionSave']);
    $r->addRoute('POST', '/ajax/hr/succession/items/{id:\d+}', ['controller' => $c, 'method' => 'successionCheck']);
    // [276] Szablony umów
    $r->addRoute('GET', '/ajax/hr/templates', ['controller' => $c, 'method' => 'templateList']);
    $r->addRoute('PUT', '/ajax/hr/templates/{code:[a-z_]+}', ['controller' => $c, 'method' => 'templateSave']);
    $r->addRoute('POST', '/ajax/hr/templates/{code:[a-z_]+}/render', ['controller' => $c, 'method' => 'templateRender']);
    $r->addRoute('POST', '/ajax/hr/templates/{code:[a-z_]+}/pdf', ['controller' => $c, 'method' => 'templatePdf']);
};
