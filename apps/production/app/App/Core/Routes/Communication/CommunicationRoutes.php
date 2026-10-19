<?php
use FastRoute\RouteCollector;
return function (RouteCollector $r) {
    $controller = App\Controllers\Web\Communication\CommunicationController::class;
    $r->addRoute('GET', '/communication/templates', ['controller' => $controller, 'method' => 'templates']);
    $r->addRoute('GET', '/communication/email-settings', ['controller' => $controller, 'method' => 'emailSettings']);
    $r->addRoute('POST', '/communication/email-settings', ['controller' => $controller, 'method' => 'saveEmailSettings']);
    $r->addRoute('POST', '/ajax/communication/email-settings/test', ['controller' => $controller, 'method' => 'testEmailSettings']);
    $r->addRoute('POST', '/communication/templates', ['controller' => $controller, 'method' => 'saveTemplate']);
    $r->addRoute('POST', '/communication/templates/{id:\\d+}', ['controller' => $controller, 'method' => 'saveTemplate']);
    $r->addRoute('GET', '/communication/compose/proforma/{id:\\d+}', ['controller' => $controller, 'method' => 'composeProforma']);
    $r->addRoute('POST', '/ajax/communication/compose/proforma/{id:\\d+}', ['controller' => $controller, 'method' => 'saveProformaMessage']);
    $r->addRoute('GET', '/communication/compose/invoice/{id:\\d+}', ['controller' => $controller, 'method' => 'composeInvoice']);
    $r->addRoute('POST', '/ajax/communication/compose/invoice/{id:\\d+}', ['controller' => $controller, 'method' => 'saveInvoiceMessage']);
    $r->addRoute('GET', '/communication/compose/wdt/{id:\\d+}', ['controller' => $controller, 'method' => 'composeWdt']);
    $r->addRoute('POST', '/ajax/communication/compose/wdt/{id:\\d+}', ['controller' => $controller, 'method' => 'saveWdtMessage']);
    $r->addRoute('GET', '/communication/compose/reminder/proforma/{id:\\d+}', ['controller' => $controller, 'method' => 'composeReminderProforma']);
    $r->addRoute('POST', '/ajax/communication/compose/reminder/proforma/{id:\\d+}', ['controller' => $controller, 'method' => 'saveReminderProformaMessage']);
    $r->addRoute('GET', '/communication/compose/reminder/difference/{id:\\d+}', ['controller' => $controller, 'method' => 'composeReminderDifference']);
    $r->addRoute('POST', '/ajax/communication/compose/reminder/difference/{id:\\d+}', ['controller' => $controller, 'method' => 'saveReminderDifferenceMessage']);
    $r->addRoute('GET', '/communication/messages', ['controller' => $controller, 'method' => 'messages']);
    $r->addRoute('POST', '/ajax/communication/messages/{id:\\d+}/send-now', ['controller' => $controller, 'method' => 'sendMessageNow']);
    $r->addRoute('GET', '/communication/messages/{id:\\d+}', ['controller' => $controller, 'method' => 'message']);
    $r->addRoute('GET', '/communication/payment-reminders', ['controller' => $controller, 'method' => 'reminder']);
    $r->addRoute('GET', '/ajax/communication/differences/{idDifference:\\d+}/compose', ['controller' => $controller, 'method' => 'ajaxComposeReminder']);
    $r->addRoute('POST', '/ajax/communication/payment-reminders/draft', ['controller' => $controller, 'method' => 'saveReminderDraft']);
};
