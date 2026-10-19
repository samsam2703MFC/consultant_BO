<?php

use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/franchise-complaints', [
        'controller' => \App\Controllers\Web\Client\Complaint\ComplaintController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('PATCH', '/ajax/franchise-complaints/{id:\d+}', [
        'controller' => \App\Controllers\Web\Client\Complaint\ComplaintController::class,
        'method'     => 'ajaxUpdateFranchiseComplaint'
    ]);





    $r->addRoute('GET', '/client-complaints/pending-products', [
        'controller' => \App\Controllers\Web\Client\Complaint\ComplaintController::class,
        'method'     => 'pendingOverview'
    ]);

    $r->addRoute('GET', '/client-complaints/pending-shipment', [
        'controller' => \App\Controllers\Web\Client\Complaint\ComplaintController::class,
        'method'     => 'pendingShipmentOverview'
    ]);;

    $r->addRoute('GET', '/client-complaints/{id:\d+}/realisation', [
        'controller' => \App\Controllers\Web\Client\Complaint\ComplaintController::class,
        'method'     => 'realisation'
    ]);

    $r->addRoute('POST', '/client-complaints/{id:\d+}/realisation', [
        'controller' => \App\Controllers\Web\Client\Complaint\ComplaintController::class,
        'method'     => 'realisation'
    ]);

    $r->addRoute('PATCH', '/ajax/client-complaints/{id:\d+}', [
        'controller' => \App\Controllers\Web\Client\Complaint\ComplaintController::class,
        'method'     => 'ajaxUpdateComplaint'
    ]);

    $r->addRoute('PATCH', '/ajax/client-complaints/pending-shipment/{id:\d+}', [
        'controller' => \App\Controllers\Web\Client\Complaint\ComplaintController::class,
        'method'     => 'ajaxUpdatePendingShipment'
    ]);

    $r->addRoute('GET', '/client-complaints/picking-list', [
        'controller' => \App\Controllers\Web\Client\Complaint\ComplaintController::class,
        'method'     => 'shippingList'
    ]);

    $r->addRoute('GET', '/ajax/client-complaints/picking-list', [
        'controller' => \App\Controllers\Web\Client\Complaint\ComplaintController::class,
        'method'     => 'ajaxGetPickingList'
    ]);

    $r->addRoute('GET', '/ajax/client-orders/picking-list', [
        'controller' => \App\Controllers\Web\Client\Complaint\ComplaintController::class,
        'method'     => 'ajaxGetPickingList'
    ]);

    $r->addRoute('GET', '/client-complaints/shipping-documents', [
        'controller' => \App\Controllers\Web\Client\Complaint\ComplaintController::class,
        'method'     => 'shippingDocuments'
    ]);

    $r->addRoute('GET', '/ajax/client-complaints/shipping-documents/{id:\d+}', [
        'controller' => \App\Controllers\Web\Client\Complaint\ComplaintController::class,
        'method'     => 'ajaxGetShippingDocument'
    ]);

    $r->addRoute('GET', '/ajax/franchise-complaints/attachment/{id}/presigned-url', [
        'controller' => \App\Controllers\Web\Client\Complaint\ComplaintController::class,
        'method'     => 'ajaxGetPresignedUrl'
    ]);

    $r->addRoute('GET', '/ajax/franchise-complaints/fetch-from-franchise', [
        'controller' => \App\Controllers\Web\Client\Complaint\ComplaintController::class,
        'method'     => 'ajaxGetComplaintsFromFranchise'
    ]);
};
