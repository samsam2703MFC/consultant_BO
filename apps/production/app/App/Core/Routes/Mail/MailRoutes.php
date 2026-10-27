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
    // Tłumacz DeepL (klucz DEEPL_API_KEY w .env serwera) : wiadomość przychodząca, odpowiedź w języku klienta, dowolny tekst.
    $r->addRoute('POST', '/ajax/mail/messages/{id:\d+}/translate', ['controller' => $c, 'method' => 'translateMessage']);
    $r->addRoute('GET', '/ajax/mail/contact-lang', ['controller' => $c, 'method' => 'contactLang']);
    $r->addRoute('GET', '/ajax/translate/status', ['controller' => $c, 'method' => 'translateStatus']);
    $r->addRoute('POST', '/ajax/translate', ['controller' => $c, 'method' => 'translateText']);
    // Ustawienia › Stopki e-maili i tłumacz (administrator)
    $f = App\Controllers\Web\Settings\MailFooterController::class;
    $r->addRoute('GET', '/settings/mail-footers', ['controller' => $f, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/settings/mail-footers', ['controller' => $f, 'method' => 'state']);
    $r->addRoute('PUT', '/ajax/settings/mail-footers', ['controller' => $f, 'method' => 'save']);
    $r->addRoute('PUT', '/ajax/settings/mail-footers/deepl-key', ['controller' => $f, 'method' => 'saveKey']);
};
