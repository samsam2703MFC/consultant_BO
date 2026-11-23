#!/usr/bin/env php
<?php
declare(strict_types=1);
/*
 * [387] Rutyny poranne AI (pilot) : uruchamia rutyny asystentów, których godzina (ai.routines_time.<kod>) już minęła
 * i które dziś jeszcze nie działały — tylko w dni robocze (week.production_days) i tylko z włączonym wyłącznikiem
 * głównym ai.routines_enabled (domyślnie 0 : skrypt nic nie robi). AI tylko PROPONUJE decyzje (n_ai_routine_proposal) ;
 * nic nie jest zapisywane w asystentach ani wysyłane.
 *   php Bin/ai_routines.php        cron co 10 minut (deploy/server.sh)
 * W dzienniku tylko statusy i liczby (bez klucza, bez danych, bez ładunku).
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../App/Core/init.php';

$container = require __DIR__ . '/../App/Core/bootstrap.php';
$lock = fopen(sys_get_temp_dir() . '/erp_ai_routines.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(['skipped' => 'already running']) . PHP_EOL;
    exit(0);
}

$service = $container->get(App\Services\Ai\MorningRoutineService::class);
if (!$service->enabled()) {
    echo json_encode(['ai_routines' => 'off (ai.routines_enabled = 0)', 'at' => date('Y-m-d H:i:s')]) . PHP_EOL;
    exit(0);
}
$runs = [];
foreach ($service->cron() as $r) {
    $runs[] = ['code' => $r['code'] ?? null, 'status' => $r['status'], 'signals' => $r['signals'] ?? 0, 'proposals' => $r['proposals'] ?? 0,
        'rejected' => $r['rejected'] ?? 0, 'cost_pln' => $r['cost_pln'] ?? 0, 'error' => $r['error'] ?? null];
}
echo json_encode(['ai_routines' => $runs, 'at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE) . PHP_EOL;
