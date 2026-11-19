#!/usr/bin/env php
<?php
declare(strict_types=1);
/*
 * [341] Odpowiedzi na adresy ERP (accounting@, logistyka@, produkcja@ — Konfiguracja poczty e-mail) : pobiera nowe
 * wiadomości ze skrzynek IMAP wszystkich aktywnych kont wysyłki.
 *   php Bin/communication_imap_sync.php [limit]     cron co 5 minut
 * W dzienniku tylko liczby (bez adresów, tematów ani haseł).
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../App/Core/init.php';

$container = require __DIR__ . '/../App/Core/bootstrap.php';
$lock = fopen(sys_get_temp_dir() . '/erp_communication_imap_sync.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(['skipped' => 'already running']) . PHP_EOL;
    exit(0);
}
$limit = max(1, min(500, (int) ($argv[1] ?? 100)));
try {
    $r = $container->get(App\Services\Communication\CommunicationImapSyncWorker::class)->syncActive($limit);
    $errors = count(array_filter($r['accounts'], fn($a) => isset($a['error'])));
    echo json_encode(['communication_imap_sync' => ['accounts' => count($r['accounts']), 'imported' => $r['imported'],
        'errors' => $errors, 'at' => date('Y-m-d H:i:s')]]) . PHP_EOL;
} catch (Throwable $e) {
    // Brak aktywnego konta : nic do pobrania (normalne przed konfiguracją).
    echo json_encode(['communication_imap_sync' => ['skipped' => 'no active account', 'at' => date('Y-m-d H:i:s')]]) . PHP_EOL;
}
