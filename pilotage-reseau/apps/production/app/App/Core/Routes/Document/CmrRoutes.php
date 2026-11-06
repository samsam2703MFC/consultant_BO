<?php

use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/ajax/cmr-documents/{id:\d+}/document', [
        'controller' => App\Controllers\Web\Document\CMRController::class,
        'method'     => 'ajaxGenerateDocument'
    ]);

    $r->addRoute('GET', '/ajax/cmr-documents/{id:\d+}/document/asia', [
        'controller' => App\Controllers\Web\Document\CMRController::class,
        'method'     => 'ajaxGenerateDocumentAsia'
    ]);

};