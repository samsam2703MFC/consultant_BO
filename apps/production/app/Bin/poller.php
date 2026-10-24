#!/usr/bin/env php
<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 0);
require_once __DIR__ . '/../public/vendor/autoload.php';

use Dotenv\Dotenv;

$root = dirname(__DIR__); // katalog projektu
if (file_exists($root . '/.env')) {
    $dotenv = Dotenv::createImmutable($root);
    $dotenv->safeLoad(); // nie wywala, gdy brak .env
}
// autoload + DI
$container = require __DIR__ . '/../App/Core/bootstrap.php';

// (opcjonalnie) blokada, żeby nie odpalać równolegle
$lock = fopen(sys_get_temp_dir() . '/orders_collector.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    // inna instancja jeszcze działa
    exit(0);
}
try {
    $orderSvc = $container->get(\App\Services\Client\Order\OrderCollectorService::class);
    $orderSvc->runCollector(50);
} catch (Throwable $e) {
    error_log(date('Y-m-d H:i:s') . ' [poller:order] ' . $e->getMessage());
}

try {
    $complaintSvc = $container->get(\App\Services\Client\Complaint\ComplaintCollectorService::class);
    $complaintSvc->runCollector(50);
} catch (Throwable $e) {
    error_log(date('Y-m-d H:i:s') .' [poller:complaint] ' . $e->getMessage());
}

flock($lock, LOCK_UN);
fclose($lock);