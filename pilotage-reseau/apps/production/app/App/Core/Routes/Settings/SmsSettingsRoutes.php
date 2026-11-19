<?php

use FastRoute\RouteCollector;

/** [313] Ustawienia › SMS (SMSAPI, ta sama bramka co CRM). */
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Settings\SmsSettingsController::class;
    $r->addRoute('GET', '/settings/sms', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/settings/sms', ['controller' => $c, 'method' => 'state']);
    $r->addRoute('PUT', '/ajax/settings/sms', ['controller' => $c, 'method' => 'save']);
    $r->addRoute('PUT', '/ajax/settings/sms/key', ['controller' => $c, 'method' => 'saveKey']);
    $r->addRoute('POST', '/ajax/settings/sms/test', ['controller' => $c, 'method' => 'test']);
};
