<?php
declare(strict_types=1);

require __DIR__ . '/../public/vendor/autoload.php';

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

function expectReminderRoute(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$routes = require __DIR__ . '/../App/Core/Routes/Communication/CommunicationRoutes.php';
$dispatcher = FastRoute\simpleDispatcher(static function (RouteCollector $collector) use ($routes): void {
    $routes($collector);
});

$proformaReminder = $dispatcher->dispatch('POST', '/ajax/communication/compose/reminder/proforma/351');
expectReminderRoute(
    $proformaReminder[0] === Dispatcher::FOUND && $proformaReminder[1]['method'] === 'saveReminderProformaMessage' && $proformaReminder[2]['id'] === '351',
    'A proforma reminder POST must reach saveReminderProformaMessage.'
);

$standardProforma = $dispatcher->dispatch('POST', '/ajax/communication/compose/proforma/351');
expectReminderRoute(
    $standardProforma[0] === Dispatcher::FOUND && $standardProforma[1]['method'] === 'saveProformaMessage',
    'A standard proforma POST must continue to reach saveProformaMessage.'
);

$sendNow = $dispatcher->dispatch('POST', '/ajax/communication/messages/99/send-now');
expectReminderRoute(
    $sendNow[0] === Dispatcher::FOUND && $sendNow[1]['method'] === 'sendMessageNow' && $sendNow[2]['id'] === '99',
    'The immediate-send endpoint must reach sendMessageNow.'
);

echo "communication_reminder_route_e2e_test: OK\n";
