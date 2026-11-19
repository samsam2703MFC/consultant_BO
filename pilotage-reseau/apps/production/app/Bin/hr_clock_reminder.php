#!/usr/bin/env php
<?php
declare(strict_types=1);
/*
 * [348] HR › Grafik tygodnia : SMS à la personne qui n'a pas pointé son entrée N minutes après le début de son grafik
 * (hr.clock_sms_delay), avant la fin du grafik ; pas un jour férié PL, pas pendant une absence, un seul SMS par jour.
 *   php Bin/hr_clock_reminder.php     cron chaque minute
 * Journal : seulement des nombres (jamais de numéro ni de nom), et seulement quand un SMS a été traité.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../App/Core/init.php';

$container = require __DIR__ . '/../App/Core/bootstrap.php';
// [295] Interrupteur des envois automatiques (Ustawienia › Parametry, system.auto_send) : à 0, rien ne part
// (sans ligne au journal : ce script tourne chaque minute).
if (!App\Core\AutoSend::enabled()) exit(0);
$lock = fopen(sys_get_temp_dir() . '/erp_hr_clock_reminder.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(['hr_clock_reminder' => ['skipped' => 'already running', 'at' => date('Y-m-d H:i:s')]]) . PHP_EOL;
    exit(0);
}
try {
    $r = $container->get(App\Services\Hr\ClockReminderService::class)->run();
    if ($r['sent'] + $r['logged'] + $r['failed'] > 0) echo json_encode(['hr_clock_reminder' => $r + ['at' => date('Y-m-d H:i:s')]]) . PHP_EOL;
} catch (Throwable $e) {
    // Base avant la migration 207 ou erreur SQL : la classe d'erreur seulement (pas de message : il pourrait citer des données).
    echo json_encode(['hr_clock_reminder' => ['error' => get_class($e), 'at' => date('Y-m-d H:i:s')]]) . PHP_EOL;
    exit(1);
}
