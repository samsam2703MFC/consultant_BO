#!/usr/bin/env php
<?php
declare(strict_types=1);
/*
 * Poczta użytkowników : pobiera nowe wiadomości ze skrzynek podłączonych w ERP (Ustawienia › Moja skrzynka e-mail),
 * żeby licznik w menu i wyszukiwarka były aktualne bez otwierania strony Poczta.
 *   php Bin/user_mail_sync.php        cron co 5 minut
 * W dzienniku tylko liczby (bez adresów, tematów ani haseł).
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../App/Core/init.php';

$container = require __DIR__ . '/../App/Core/bootstrap.php';
$lock = fopen(sys_get_temp_dir() . '/erp_user_mail_sync.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(['skipped' => 'already running']) . PHP_EOL;
    exit(0);
}

$pdo = $container->get(PDO::class);
$service = $container->get(App\Services\Mail\UserMailService::class);
$stats = ['mailboxes' => 0, 'new' => 0, 'errors' => 0];
foreach ($pdo->query('SELECT id_user FROM n_user_mailbox ORDER BY last_sync_at IS NOT NULL, last_sync_at')->fetchAll(PDO::FETCH_COLUMN) as $userId) {
    $stats['mailboxes']++;
    try {
        $stats['new'] += $service->sync((int) $userId)['new'];
    } catch (Throwable $e) {
        $stats['errors']++; // błąd zapisany przy skrzynce (last_error), widoczny w jej ustawieniach
    }
}
echo json_encode(['user_mail_sync' => $stats + ['at' => date('Y-m-d H:i:s')]]) . PHP_EOL;
