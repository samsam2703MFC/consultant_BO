#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../public/vendor/autoload.php';

use Dotenv\Dotenv;

$root = dirname(__DIR__); // katalog projektu
if (file_exists($root.'/.env')) {
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

// uruchom worker
$svc = $container->get(\App\Services\Client\Order\OrderCollectorService::class);
// to Twoja metoda, którą wcześniej szkicowaliśmy:
$svc->runOrderCollector(50);

flock($lock, LOCK_UN);

