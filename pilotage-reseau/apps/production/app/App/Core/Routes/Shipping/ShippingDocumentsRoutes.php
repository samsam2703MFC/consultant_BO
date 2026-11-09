<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/ajax/shipments/{id:\d+}/documents/grouped', [
        'controller' => App\Controllers\Web\Shipping\DocumentController::class,
        'method'     => 'ajaxGetDocuments'
    ]);

    $r->addRoute('GET', '/ajax/shipments/{id:\d+}/documents/packaging', [
        'controller' => App\Controllers\Web\Shipping\DocumentController::class,
        'method'     => 'ajaxGetPackagingDocument'
    ]);

    $r->addRoute('POST', '/ajax/shipments/{id:\d+}/packaging-evidence/current-data', [
        'controller' => App\Controllers\Web\Shipping\DocumentController::class,
        'method'     => 'ajaxCreateCurrentPackagingEvidence'
    ]);

    $r->addRoute('POST', '/ajax/shipments/{id:\d+}/ppwr-evidence/current-data', [
        'controller' => App\Controllers\Web\Shipping\DocumentController::class,
        'method'     => 'ajaxCreateCurrentPpwrEvidence'
    ]);

    $r->addRoute('GET', '/ajax/shipments/{id:\d+}/documents/ppwr', [
        'controller' => App\Controllers\Web\Shipping\DocumentController::class,
        'method'     => 'ajaxGetPpwrDocument'
    ]);

    $r->addRoute('GET', '/ajax/shipments/{id:\d+}/packaging-evidence/missing-items', [
        'controller' => App\Controllers\Web\Shipping\DocumentController::class,
        'method'     => 'ajaxGetMissingPackagingItems'
    ]);

    $r->addRoute('PATCH', '/ajax/shipments/{shipmentId:\d+}/packaging-evidence/items/{itemId:\d+}', [
        'controller' => App\Controllers\Web\Shipping\DocumentController::class,
        'method'     => 'ajaxUpdateMissingPackagingItem'
    ]);

    $r->addRoute('GET', '/ajax/complaints/{id:\d+}/documents/grouped', [
        'controller' => App\Controllers\Web\Shipping\DocumentController::class,
        'method'     => 'ajaxGetComplaintDocuments'
    ]);


};
