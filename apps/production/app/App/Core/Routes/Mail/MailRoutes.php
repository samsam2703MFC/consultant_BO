<?php

use FastRoute\RouteCollector;

// Poczta użytkownika : własna skrzynka każdego operatora (Ustawienia › Moja skrzynka e-mail).
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Mail\MailController::class;
    $r->addRoute('GET', '/mail', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/mail/settings', ['controller' => $c, 'method' => 'settings']);
    $r->addRoute('GET', '/mail/messages/{id:\d+}/attachments/{index:\d+}', ['controller' => $c, 'method' => 'attachment']);
    $r->addRoute('GET', '/ajax/mail/state', ['controller' => $c, 'method' => 'state']);
    $r->addRoute('PUT', '/ajax/mail/settings', ['controller' => $c, 'method' => 'saveSettings']);
    $r->addRoute('DELETE', '/ajax/mail/settings', ['controller' => $c, 'method' => 'deleteSettings']);
    $r->addRoute('POST', '/ajax/mail/settings/test', ['controller' => $c, 'method' => 'test']);
    $r->addRoute('POST', '/ajax/mail/sync', ['controller' => $c, 'method' => 'sync']);
    $r->addRoute('GET', '/ajax/mail/messages', ['controller' => $c, 'method' => 'messages']);
    $r->addRoute('GET', '/ajax/mail/messages/{id:\d+}', ['controller' => $c, 'method' => 'message']);
    $r->addRoute('POST', '/ajax/mail/send', ['controller' => $c, 'method' => 'send']);
};
