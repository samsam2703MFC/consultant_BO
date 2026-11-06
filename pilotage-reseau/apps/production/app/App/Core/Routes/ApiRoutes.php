<?php
namespace App\Core\Routes;

use FastRoute\RouteCollector;

class ApiRoutes
{
    public static function getDispatcher(): \FastRoute\Dispatcher
    {
        return \FastRoute\simpleDispatcher(function (RouteCollector $r) {


            $r->addRoute('GET', '/api/v1/test', [
                'controller' => \App\Controllers\Api\V1\Invoice\ProformaController::class,
                'method' => 'test'
            ]);



            $r->addRoute('POST', '/api/v1/client-orders', [
                'controller' => \App\Controllers\Api\V1\ClientOrder\ClientOrderController::class,
                'method' => 'insertOrder'
            ]);

            $r->addRoute('POST', '/api/v1/integrate-client', [
                'controller' => \App\Controllers\Api\V1\Client\ClientController::class,
                'method' => 'registerClientAsIntegrated'
            ]);

            $r->addRoute('GET', '/api/internal/v1/client-orders', [
                'controller' => \App\Controllers\Api\Internal\ClientOrderController::class,
                'method' => 'index',
            ]);

            $r->addRoute('POST', '/api/internal/v1/picking-lists', [
                'controller' => \App\Controllers\Api\Internal\PickingListController::class,
                'method' => 'create',
            ]);

            $r->addRoute('POST', '/api/internal/v1/picking-lists/preview', [
                'controller' => \App\Controllers\Api\Internal\PickingListController::class,
                'method' => 'preview',
            ]);

            $r->addRoute('POST', '/api/internal/v1/production-lists/preview', [
                'controller' => \App\Controllers\Api\Internal\ProductionListController::class,
                'method' => 'preview',
            ]);

            $r->addRoute('POST', '/api/internal/v1/production-lists/pdf', [
                'controller' => \App\Controllers\Api\Internal\ProductionListController::class,
                'method' => 'pdf',
            ]);

            $r->addRoute('GET', '/api/internal/v1/production-lists/complaint', [
                'controller' => \App\Controllers\Api\Internal\ProductionListController::class,
                'method' => 'complaintPreview',
            ]);

            $r->addRoute('POST', '/api/internal/v1/production-lists/complaint/pdf', [
                'controller' => \App\Controllers\Api\Internal\ProductionListController::class,
                'method' => 'complaintPdf',
            ]);

            $r->addRoute('POST', '/api/internal/v1/requirement-lists/preview', [
                'controller' => \App\Controllers\Api\Internal\ProductionListController::class,
                'method' => 'requirementPreview',
            ]);

            $r->addRoute('POST', '/api/internal/v1/requirement-lists/pdf', [
                'controller' => \App\Controllers\Api\Internal\ProductionListController::class,
                'method' => 'requirementPdf',
            ]);

        });
    }
}
