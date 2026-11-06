#!/usr/bin/env php
<?php
declare(strict_types=1);
/*
 * Prognoza 13 tygodni [220] : odczyt gam sezonowych i sprzedaży naszych produktów w sklepach z API panelu TF Buddy
 * (tylko GET) do n_forecast_panel_read — gdy ostatni odczyt jest sprzed poniedziałku 6:00 (raz w tygodniu) albo z --force.
 * Tygodnie sprzedaży już odczytane nie są pytane drugi raz.
 *   php Bin/forecast_panel_read.php [--force]     cron : codziennie 06:10 (czyta tylko raz w tygodniu)
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../App/Core/init.php';

$container = require __DIR__ . '/../App/Core/bootstrap.php';
// Verrou par utilisateur : le premier relevé (déploiement, root) ne bloque pas le cron (www-data).
$lock = fopen(sys_get_temp_dir() . '/erp_forecast_panel_read_' . getmyuid() . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(['skipped' => 'already running']) . PHP_EOL;
    exit(0);
}
$panel = $container->get(App\Services\Forecast\PanelSeasonService::class);
$params = $container->get(App\Repositories\Workshop\ParamRepository::class);
if (!$panel->configured()) {
    echo json_encode(['skipped' => 'panel API account not configured']) . PHP_EOL;
    exit(0);
}
$last = $panel->last();
if ($last !== null && !$last['stale'] && !in_array('--force', $argv, true)) {
    echo json_encode(['skipped' => 'fresh', 'read_at' => $last['read_at']]) . PHP_EOL;
    exit(0);
}
$weeks = max(4, min(26, (int) $params->get('forecast.weeks', '13')));
$trend = max(4, min(26, (int) $params->get('forecast.trend_weeks', '8')));
$t0 = microtime(true);
try {
    $data = $panel->refresh(null, null, $weeks, $trend);
    echo json_encode(['read_at' => $data['read_at'] ?? null, 'periods' => count($data['periods'] ?? []), 'products' => count($data['products'] ?? []),
        'sales_weeks' => count($data['sales'] ?? []), 'sales_failed' => count($data['sales_failed'] ?? []), 's' => round(microtime(true) - $t0, 1)]) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'forecast_panel_read: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
