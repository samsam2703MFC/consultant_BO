<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    // Ustawienia : hub z kartami (audyt 2, N6).
    $r->addRoute('GET', '/settings', [
        'controller' => App\Controllers\Web\Settings\SettingsHubController::class,
        'method'     => 'index'
    ]);

    $r->addRoute('GET', '/other-settings', [
        'controller' => App\Controllers\Web\Settings\SettingsController::class,
        'method'     => 'index'
    ]);

    $r->addRoute('POST', '/ajax/settings/stock-params', [
        'controller' => App\Controllers\Web\Settings\SettingsController::class,
        'method'     => 'saveStockParams'
    ]);

    $r->addRoute('POST', '/ajax/settings/alert-params', [
        'controller' => App\Controllers\Web\Settings\SettingsController::class,
        'method'     => 'saveAlertParams'
    ]);

    // Ceny nowych produktów [198] : współczynniki fabryki, mnożniki sklepu, zaokrąglenie.
    $r->addRoute('POST', '/ajax/settings/new-product-params', [
        'controller' => App\Controllers\Web\Settings\SettingsController::class,
        'method'     => 'saveNewProductParams'
    ]);

    $r->addRoute('GET', '/settings/ksef', [
        'controller' => App\Controllers\Web\Settings\KsefSettingsController::class,
        'method' => 'index'
    ]);

    $r->addRoute('GET', '/settings/ksef/purchase-invoices', [
        'controller' => App\Controllers\Web\Settings\KsefPurchaseInvoiceController::class,
        'method' => 'index'
    ]);

    $r->addRoute('POST', '/ajax/settings/ksef/purchase-invoices', [
        'controller' => App\Controllers\Web\Settings\KsefPurchaseInvoiceController::class,
        'method' => 'ajaxList'
    ]);

    $r->addRoute('GET', '/settings/ksef/purchase-invoices/{ksefNumber}', [
        'controller' => App\Controllers\Web\Settings\KsefPurchaseInvoiceController::class,
        'method' => 'xml'
    ]);

    $r->addRoute('POST', '/ajax/settings/ksef', [
        'controller' => App\Controllers\Web\Settings\KsefSettingsController::class,
        'method' => 'ajaxSave'
    ]);

    $r->addRoute('POST', '/ajax/settings/ksef/check', [
        'controller' => App\Controllers\Web\Settings\KsefSettingsController::class,
        'method' => 'ajaxCheck'
    ]);

    $r->addRoute('GET', '/settings/page-usage', [
        'controller' => App\Controllers\Web\Settings\PageUsageController::class,
        'method'     => 'index'
    ]);

    $r->addRoute('GET', '/settings/languages', [
        'controller' => App\Controllers\Web\Settings\LanguageController::class,
        'method'     => 'index'
    ]);

    $r->addRoute('POST', '/ajax/settings/languages', [
        'controller' => App\Controllers\Web\Settings\LanguageController::class,
        'method'     => 'ajaxCreate'
    ]);

    $r->addRoute('PATCH', '/ajax/settings/languages/{id:\d+}', [
        'controller' => App\Controllers\Web\Settings\LanguageController::class,
        'method'     => 'ajaxUpdate'
    ]);

    $r->addRoute('DELETE', '/ajax/settings/languages/{id:\d+}', [
        'controller' => App\Controllers\Web\Settings\LanguageController::class,
        'method'     => 'ajaxDelete'
    ]);

};
