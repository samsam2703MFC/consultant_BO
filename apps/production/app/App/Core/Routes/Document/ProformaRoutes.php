<?php

use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/proforma-documents', [
        'controller' => App\Controllers\Web\Document\ProformaController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/ajax/proforma-documents/{id:\d+}/document', [
        'controller' => App\Controllers\Web\Document\ProformaController::class,
        'method'     => 'ajaxGenerateProformaDocument'
    ]);

    $r->addRoute('POST', '/ajax/proforma-documents/{id:\d+}/paid', [
        'controller' => App\Controllers\Web\Document\ProformaController::class,
        'method'     => 'ajaxMarkProformaPaid'
    ]);


    $r->addRoute('POST', '/ajax/client-orders/{idOrder:\d+}/proforma', [
        'controller' => App\Controllers\Web\Document\ProformaController::class,
        'method'     => 'ajaxInsertProformaFromOrder'
    ]);

    $r->addRoute('POST', '/ajax/client-orders/{idOrder:\d+}/proforma/regenerate', [
        'controller' => App\Controllers\Web\Document\ProformaController::class,
        'method'     => 'ajaxRegenerateUnpaidProforma'
    ]);


};
