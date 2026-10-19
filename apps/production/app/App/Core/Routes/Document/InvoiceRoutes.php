<?php

use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/invoice-documents', [
        'controller' => App\Controllers\Web\Document\InvoiceController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/invoice-documents/new', [
        'controller' => App\Controllers\Web\Document\InvoiceController::class,
        'method'     => 'newInvoice'
    ]);

    $r->addRoute('POST', '/invoice-documents/new', [
        'controller' => App\Controllers\Web\Document\InvoiceController::class,
        'method'     => 'newInvoice'
    ]);

    $r->addRoute('GET', '/ajax/invoice-documents/{id:\d+}/document', [
        'controller' => App\Controllers\Web\Document\InvoiceController::class,
        'method'     => 'ajaxGenerateDocument'
    ]);

    $r->addRoute('GET', '/clients/{id:\d+}/invoices/create', [
        'controller' => App\Controllers\Web\Document\InvoiceController::class,
        'method'     => 'create'
    ]);

    $r->addRoute('POST', '/clients/{id:\d+}/invoices/create', [
        'controller' => App\Controllers\Web\Document\InvoiceController::class,
        'method'     => 'create'
    ]);
};
