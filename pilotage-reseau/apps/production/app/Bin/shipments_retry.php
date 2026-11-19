<?php
// Bin/shipments_retry.php : file « Wysyłka → sklep » (webhook_outbox_shipments), une passe (aussi lancée par Bin/mail_dispatch.php).
use App\Services\Client\Order\ShipmentOutboxDispatcher;
use Dotenv\Dotenv;

require __DIR__ . '/../public/vendor/autoload.php';

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$container = require __DIR__ . '/../App/Core/bootstrap.php';
// [295] Interrupteur des envois automatiques (Ustawienia › Parametry, system.auto_send) : à 0, ce script ne fait rien.
if (App\Core\AutoSend::skip('shipments_retry')) exit(0);
echo json_encode($container->get(ShipmentOutboxDispatcher::class)->dispatchDue(50)) . PHP_EOL;
