#!/usr/bin/env php
<?php
declare(strict_types=1);
/*
 * Zadania stanowisk [227] (co 5 min) : wystąpienia bieżących okresów i zaliczenie po kreatorach, zadania automatyczne
 * (przełącznik « Automatycznie » w zadaniu : kiedy, o której, co robi), przypomnienie w dniu terminu i przełożony po N
 * dniach spóźnienia (dzwonek). Jeden przebieg naraz (blokada na użytkownika).
 *   php Bin/task_cron.php [--now="2026-11-02 06:15"]
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../App/Core/init.php';

$container = require __DIR__ . '/../App/Core/bootstrap.php';
$lock = fopen(sys_get_temp_dir() . '/erp_task_cron_' . getmyuid() . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(['skipped' => 'already running']) . PHP_EOL;
    exit(0);
}
$now = null;
foreach ($argv as $a) if (str_starts_with($a, '--now=')) $now = new DateTimeImmutable(substr($a, 6), new DateTimeZone(App\Services\Organization\TaskCronService::TZ));
try {
    $r = $container->get(App\Services\Organization\TaskCronService::class)->tick($now);
    if ($r['ran'] || $r['reminded'] || $r['escalated']) echo json_encode(['at' => App\Services\Organization\TaskCronService::now()->format('Y-m-d H:i:s')] + $r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' task_cron: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
