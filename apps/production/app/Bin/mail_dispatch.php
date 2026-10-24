#!/usr/bin/env php
<?php
declare(strict_types=1);
/*
 * E-mails automatiques : transforme les nouveaux événements (commandes, livraisons prévues,
 * expéditions, WDT, factures, proformas) en messages en file selon les règles actives
 * (Komunikacja › Automatyczne e-maile), puis passe la file d'envoi.
 *   php Bin/mail_dispatch.php        à lancer par cron toutes les 5 minutes
 * L'envoi réel dépend de MAIL_DELIVERY_MODE (.env) : live, redirect ou log_only.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../App/Core/init.php';

$container = require __DIR__ . '/../App/Core/bootstrap.php';
$lock = fopen(sys_get_temp_dir() . '/erp_mail_dispatch.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(['skipped' => 'already running']) . PHP_EOL;
    exit(0);
}

try {
    $dispatch = $container->get(App\Services\Communication\MailAutomationService::class)->dispatch();
    $delivery = $container->get(App\Services\Communication\CommunicationEmailDeliveryWorker::class)->processDue(50);
    echo json_encode(['dispatch' => $dispatch, 'delivery' => $delivery], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'mail_dispatch: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
