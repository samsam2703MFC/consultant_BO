<?php
use FastRoute\RouteCollector;

// [271] Rail « HR » : pracownicy, karta pracownika (dane, dokumenty, notatki), dokumenty, notatki miesiąca, ustawienia.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Hr\HrController::class;

    $r->addRoute('GET', '/hr', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/hr/documents', ['controller' => $c, 'method' => 'documentsPage']);
    $r->addRoute('GET', '/hr/notes', ['controller' => $c, 'method' => 'notesPage']);
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
};
