<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r): void {
    $r->addRoute('GET', '/settings/api-devices', [
        'controller' => App\Controllers\Web\Integration\InternalApiDeviceController::class,
        'method' => 'overview',
    ]);
    $r->addRoute('GET', '/devices', [
        'controller' => App\Controllers\Web\Integration\InternalApiDeviceController::class,
        'method' => 'overview',
    ]);
    $r->addRoute('GET', '/settings/api-devices/new', [
        'controller' => App\Controllers\Web\Integration\InternalApiDeviceController::class,
        'method' => 'create',
    ]);
    $r->addRoute('POST', '/settings/api-devices/new', [
        'controller' => App\Controllers\Web\Integration\InternalApiDeviceController::class,
        'method' => 'create',
    ]);
    $r->addRoute('GET', '/devices/new', [
        'controller' => App\Controllers\Web\Integration\InternalApiDeviceController::class,
        'method' => 'create',
    ]);
    $r->addRoute('POST', '/devices/new', [
        'controller' => App\Controllers\Web\Integration\InternalApiDeviceController::class,
        'method' => 'create',
    ]);
    $r->addRoute('GET', '/devices/logs', [
        'controller' => App\Controllers\Web\Integration\DeviceLogController::class,
        'method' => 'overview',
    ]);
    $r->addRoute('POST', '/settings/api-devices/{id:\\d+}/rotate', [
        'controller' => App\Controllers\Web\Integration\InternalApiDeviceController::class,
        'method' => 'rotate',
    ]);
    $r->addRoute('POST', '/settings/api-devices/{id:\\d+}/toggle', [
        'controller' => App\Controllers\Web\Integration\InternalApiDeviceController::class,
        'method' => 'toggle',
    ]);
    $r->addRoute('GET', '/settings/picking-list-generations', [
        'controller' => App\Controllers\Web\Integration\PickingListGenerationController::class,
        'method' => 'overview',
    ]);
    $r->addRoute('GET', '/settings/picking-list-generations/{id:\\d+}', [
        'controller' => App\Controllers\Web\Integration\PickingListGenerationController::class,
        'method' => 'show',
    ]);
    $r->addRoute('GET', '/archive/documents/proformas', [
        'controller' => App\Controllers\Web\Document\ProformaController::class,
        'method' => 'overview',
    ]);
    $r->addRoute('GET', '/archive/documents/invoices', [
        'controller' => App\Controllers\Web\Document\InvoiceController::class,
        'method' => 'overview',
    ]);
    $r->addRoute('GET', '/archive/documents/returns', [
        'controller' => App\Controllers\Web\Client\Complaint\ComplaintController::class,
        'method' => 'shippingDocuments',
    ]);
    $r->addRoute('GET', '/archive/documents/picking-lists', [
        'controller' => App\Controllers\Web\Integration\PickingListGenerationController::class,
        'method' => 'overview',
    ]);
    $r->addRoute('GET', '/archive/documents/future/{type:production-lists|requirement-lists|complaint-production-lists|complaint-picking-lists}', [
        'controller' => App\Controllers\Web\Integration\DocumentArchiveController::class,
        'method' => 'future',
    ]);
};
