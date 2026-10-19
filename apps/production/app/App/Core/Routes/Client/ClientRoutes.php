<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/clients', [
        'controller' => App\Controllers\Web\Client\ClientController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/clients/new', [
        'controller' => App\Controllers\Web\Client\ClientController::class,
        'method'     => 'newClient'
    ]);

    $r->addRoute('POST', '/clients/new', [
        'controller' => App\Controllers\Web\Client\ClientController::class,
        'method'     => 'newClient'
    ]);

    $r->addRoute('GET', '/clients/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Client\ClientController::class,
        'method'     => 'editClient'
    ]);

    $r->addRoute('POST', '/clients/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Client\ClientController::class,
        'method'     => 'editClient'
    ]);

    $r->addRoute('GET', '/clients/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Client\ClientController::class,
        'method'     => 'deleteClient'
    ]);

    $r->addRoute('POST', '/clients/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Client\ClientController::class,
        'method'     => 'deleteClient'
    ]);


    $r->addRoute('PATCH', '/ajax/clients/{id:\d+}', [
        'controller' => App\Controllers\Web\Client\ClientController::class,
        'method'     => 'ajaxUpdateClient'
    ]);

    $r->addRoute('POST', '/ajax/clients/vies-lookup', [
        'controller' => App\Controllers\Web\Client\ClientController::class,
        'method'     => 'ajaxViesLookup'
    ]);

    $r->addRoute('GET', '/clients/{id:\d+}/profitability', [
        'controller' => App\Controllers\Web\Client\ClientController::class,
        'method'     => 'profitability'
    ]);

    $r->addRoute('GET', '/ajax/clients/{id:\d+}/integration-invitation-code', [
        'controller' => App\Controllers\Web\Client\ClientController::class,
        'method'     => 'ajaxGenerateIntegrationInvitationCodeForFranchiseClient'
    ]);

    $r->addRoute('POST', '/ajax/clients/{id:\d+}/activate-integration', [
        'controller' => App\Controllers\Web\Client\ClientController::class,
        'method'     => 'ajaxActivateIntegration'
    ]);

    $r->addRoute('GET', '/clients/{id:\d+}/sales-summary', [
        'controller' => App\Controllers\Web\Client\ClientController::class,
        'method'     => 'salesSummary'
    ]);

    $r->addRoute('GET', '/ajax/clients/{id:\d+}/sales-summary', [
        'controller' => App\Controllers\Web\Client\ClientController::class,
        'method'     => 'ajaxSalesSummary'
    ]);

    $r->addRoute('GET', '/ajax/clients/{id:\d+}/sales-summary/export/pdf', [
        'controller' => App\Controllers\Web\Client\ClientController::class,
        'method'     => 'exportSalesSummaryPdf'
    ]);

    $r->addRoute('GET', '/ajax/clients/{id:\d+}/sales-summary/export/xls', [
        'controller' => App\Controllers\Web\Client\ClientController::class,
        'method'     => 'exportSalesSummaryXls'
    ]);


};
